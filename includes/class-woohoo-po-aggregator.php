<?php
defined( 'ABSPATH' ) || exit;

/**
 * Produktübersicht ("Packliste") aggregation.
 *
 * Ported from the "DT konsolidierte Produktliste Lokal/Postversand" n8n
 * workflows: groups open orders' line items by parent product, summing
 * quantities across units (kg/g/l/ml/cl/mg, "Xer"/"X Paar" pack sizes) so
 * warehouse staff see one consolidated line per product instead of one row
 * per order. Pure query + math - no WordPress output, no HTML - so this
 * class stays testable independent of the request/response layer that
 * Woohoo_Product_Overview owns.
 *
 * Every value returned here is plain scalars meant for a JSON response;
 * callers must still esc_html()/textContent-render them - this class does
 * not decide how the caller escapes output.
 */
final class Woohoo_PO_Aggregator {

    const LOCAL_DATE_META = '_willii_delivery_date';
    const POST_METHOD_TITLE = 'Postversand';

    private const UNIT_CONVERSIONS = [
        'kg' => [ 1000, 'g' ],
        'g'  => [ 1, 'g' ],
        'l'  => [ 1000, 'ml' ],
        'ml' => [ 1, 'ml' ],
        'cl' => [ 10, 'ml' ],
        'mg' => [ 0.001, 'g' ],
    ];

    /**
     * Local delivery: orders whose "_willii_delivery_date" meta (written by
     * the site's delivery-date plugin) matches the requested date, still
     * open (processing, not yet completed).
     *
     * @param string   $date              Y-m-d.
     * @param string[] $exclude_postcodes
     */
    public static function get_local_summary( string $date, array $exclude_postcodes ): array {
        $orders = wc_get_orders( [
            'status'   => [ 'processing' ],
            'meta_key' => self::LOCAL_DATE_META,
            'meta_value' => $date,
            'limit'    => -1,
            'return'   => 'objects',
        ] );

        $orders = array_filter( $orders, function ( \WC_Order $order ) use ( $date ) {
            return $order->get_date_completed() === null
                && (string) $order->get_meta( self::LOCAL_DATE_META ) === $date;
        } );

        return self::aggregate( $orders, $exclude_postcodes );
    }

    /**
     * Postversand: all currently open orders (processing, not completed)
     * shipped via "Postversand" - date-agnostic by design (mirrors the n8n
     * workflow, which never filtered Postversand by date either).
     *
     * @param string[] $exclude_postcodes
     */
    public static function get_post_summary( array $exclude_postcodes ): array {
        $orders = wc_get_orders( [
            'status' => [ 'processing' ],
            'limit'  => -1,
            'return' => 'objects',
        ] );

        $orders = array_filter( $orders, function ( \WC_Order $order ) {
            return $order->get_date_completed() === null
                && self::shipping_method_title( $order ) === self::POST_METHOD_TITLE;
        } );

        return self::aggregate( $orders, $exclude_postcodes );
    }

    private static function shipping_method_title( \WC_Order $order ): string {
        foreach ( $order->get_shipping_methods() as $shipping_item ) {
            return (string) $shipping_item->get_method_title();
        }
        return 'Unbekannt';
    }

    /**
     * @param \WC_Order[] $orders
     * @param string[]    $exclude_postcodes
     */
    private static function aggregate( array $orders, array $exclude_postcodes ): array {
        $groups = [];

        foreach ( $orders as $order ) {
            if ( in_array( $order->get_shipping_postcode(), $exclude_postcodes, true ) ) {
                continue;
            }

            $customer_name   = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
            $shipping_method = self::shipping_method_title( $order );

            foreach ( $order->get_items( 'line_item' ) as $item ) {
                /** @var \WC_Order_Item_Product $item */
                self::aggregate_item( $groups, $item, $customer_name, $shipping_method );
            }
        }

        $result = array_values( $groups );
        usort( $result, fn( array $a, array $b ) => $b['total'] <=> $a['total'] );

        foreach ( $result as &$group ) {
            $group['total_label'] = self::format_weight( $group['total'], $group['unit'] );
            foreach ( $group['orders'] as &$order_row ) {
                $order_row['qty_label'] = self::format_menge( $order_row, $group['unit'] );
                unset( $order_row['weight_per_unit'], $order_row['multiplier'], $order_row['raw_qty'], $order_row['calc_qty'], $order_row['unit'] );
            }
            unset( $order_row );
            unset( $group['total'], $group['unit'] );
        }
        unset( $group );

        return [ 'groups' => $result ];
    }

    private static function aggregate_item( array &$groups, \WC_Order_Item_Product $item, string $customer_name, string $shipping_method ): void {
        $product_id = $item->get_product_id();
        $parent     = $product_id ? wc_get_product( $product_id ) : null;
        $name       = $parent ? $parent->get_name() : $item->get_name();
        $sku        = $parent ? $parent->get_sku() : '';

        // Keyed by product ID rather than display name: two distinct products
        // can share the same name, and a renamed product would otherwise
        // split its own history across two group rows. Falls back to a
        // name-based key only when there's no product ID (e.g. the product
        // was since deleted and get_product_id() has nothing to point at).
        $key = $product_id ? 'id:' . $product_id : 'name:' . $name;

        $weight_str = self::get_meta( $item, [ 'pa_gewicht', 'Gewicht' ] );
        $weight     = self::parse_weight( $weight_str );
        $variant    = self::get_meta( $item, [ 'pa_variante', 'Variante' ] );
        $portion    = self::get_meta( $item, [ 'pa_portionierung', 'Portionierung' ] );
        $multiplier = self::parse_pack_multiplier( $item->get_name() );
        $variant_str = implode( ', ', array_filter( [ $variant, $portion ] ) ) ?: '—';

        if ( ! isset( $groups[ $key ] ) ) {
            $groups[ $key ] = [
                'name'   => $name,
                'sku'    => $sku,
                'total'  => 0.0,
                'unit'   => $weight ? $weight['unit'] : 'Stück',
                'orders' => [],
            ];
        }

        $qty = $weight ? $item->get_quantity() * $weight['val'] : $item->get_quantity() * $multiplier;
        $groups[ $key ]['total'] += $qty;

        $weight_per_unit = $weight ? $weight['val'] : null;
        $found_index = null;
        foreach ( $groups[ $key ]['orders'] as $i => $existing ) {
            if ( $existing['customer_name'] === $customer_name
                && $existing['variant'] === $variant_str
                && $existing['shipping_method'] === $shipping_method
                && $existing['weight_per_unit'] === $weight_per_unit
            ) {
                $found_index = $i;
                break;
            }
        }

        if ( $found_index !== null ) {
            $groups[ $key ]['orders'][ $found_index ]['raw_qty']  += $item->get_quantity();
            $groups[ $key ]['orders'][ $found_index ]['calc_qty'] += $qty;
            return;
        }

        $groups[ $key ]['orders'][] = [
            'customer_name'   => $customer_name,
            'shipping_method' => $shipping_method,
            'variant'         => $variant_str,
            'raw_qty'         => $item->get_quantity(),
            'calc_qty'        => $qty,
            'unit'            => $weight ? $weight['unit'] : 'Stück',
            'weight_per_unit' => $weight_per_unit,
            'multiplier'      => $multiplier,
        ];
    }

    /**
     * Reads product-attribute-style order item meta (weight/variant/portion),
     * matching either the raw meta key (e.g. "pa_gewicht") or its human
     * display label (e.g. "Gewicht") - orders carry either depending on how
     * the attribute was set up on the product. get_formatted_meta_data()
     * already resolves slug values (e.g. a pa_gewicht term) to their
     * display label, which is what we want here.
     *
     * @param string[] $keys
     */
    private static function get_meta( \WC_Order_Item_Product $item, array $keys ): ?string {
        foreach ( $item->get_formatted_meta_data( '_', true ) as $meta ) {
            if ( in_array( $meta->key, $keys, true ) || in_array( $meta->display_key, $keys, true ) ) {
                $value = wp_strip_all_tags( (string) $meta->display_value );
                return $value !== '' ? $value : null;
            }
        }
        return null;
    }

    private static function parse_weight( ?string $str ): ?array {
        if ( $str === null || $str === '' ) {
            return null;
        }
        if ( ! preg_match( '/(\d+(?:[.,]\d+)?)\s*(kg|g|ml|l|cl|mg)\b/i', $str, $m ) ) {
            return null;
        }

        $val  = (float) str_replace( ',', '.', $m[1] );
        $unit = strtolower( $m[2] );
        if ( ! isset( self::UNIT_CONVERSIONS[ $unit ] ) ) {
            return null;
        }

        [ $factor, $target_unit ] = self::UNIT_CONVERSIONS[ $unit ];
        return [ 'val' => $val * $factor, 'unit' => $target_unit ];
    }

    private static function parse_pack_multiplier( string $name ): int {
        if ( preg_match( '/(\d+)er\b/i', $name, $m ) ) {
            return (int) $m[1];
        }
        if ( preg_match( '/(\d+)\s*Paar\b/i', $name, $m ) ) {
            return (int) $m[1] * 2;
        }
        return 1;
    }

    /** German-locale number formatting (comma decimal separator), trimmed of trailing zeros. */
    private static function format_number( float $val ): string {
        if ( abs( $val - round( $val ) ) < 0.0001 ) {
            return (string) (int) round( $val );
        }
        return rtrim( rtrim( number_format( $val, 2, ',', '' ), '0' ), ',' );
    }

    /**
     * $val is nullable defensively: within a weight-based group, one line
     * item could theoretically lack parseable weight meta while a sibling
     * item of the same product key set the group's unit. Treated as 0
     * rather than left to fatal on a null arithmetic op.
     */
    private static function format_weight( ?float $val, string $unit ): string {
        $val = $val ?? 0.0;
        if ( $unit === 'g' && $val >= 1000 ) {
            return self::format_number( $val / 1000 ) . ' kg';
        }
        if ( $unit === 'ml' && $val >= 1000 ) {
            return self::format_number( $val / 1000 ) . ' l';
        }
        return self::format_number( $val ) . ' ' . $unit;
    }

    private static function format_menge( array $order_row, string $group_unit ): string {
        if ( $group_unit === 'Stück' ) {
            if ( $order_row['multiplier'] > 1 ) {
                return sprintf( '%d × %d Stück = %d Stück', $order_row['raw_qty'], $order_row['multiplier'], $order_row['calc_qty'] );
            }
            return self::format_number( $order_row['calc_qty'] ) . ' Stück';
        }

        if ( $order_row['raw_qty'] > 1 ) {
            return sprintf(
                '%d × %s = %s',
                $order_row['raw_qty'],
                self::format_weight( $order_row['weight_per_unit'], $group_unit ),
                self::format_weight( $order_row['calc_qty'], $group_unit )
            );
        }

        return self::format_weight( $order_row['calc_qty'], $group_unit );
    }
}
