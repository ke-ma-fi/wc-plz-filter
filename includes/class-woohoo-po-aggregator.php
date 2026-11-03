<?php
defined( 'ABSPATH' ) || exit;

/**
 * Produktübersicht ("Packliste") aggregation.
 *
 * Ported from the "DT konsolidierte Produktliste Lokal/Postversand" n8n
 * workflows: groups open orders' line items by parent product, summing
 * quantities across units (kg/g/l/ml/cl/mg, "Xer"/"X Paar" pack sizes) so
 * warehouse staff see one consolidated line per product instead of one row
 * per order. Refunded quantities are netted out, and a product whose lines
 * come in different unit dimensions (mass, volume, pieces) keeps one
 * subtotal per dimension ("3,5 kg + 4 Stück") instead of adding grams to
 * pieces. Pure query + math - no WordPress output, no HTML - so this
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

    private const PIECE_UNIT = 'Stück';

    private const UNIT_CONVERSIONS = [
        'kg' => [ 1000, 'g' ],
        'g'  => [ 1, 'g' ],
        'l'  => [ 1000, 'ml' ],
        'ml' => [ 1, 'ml' ],
        'cl' => [ 10, 'ml' ],
        'mg' => [ 0.001, 'g' ],
    ];

    /** Spelled-out / colloquial unit names, normalised to a UNIT_CONVERSIONS key. */
    private const UNIT_ALIASES = [
        'kilogramm'  => 'kg',
        'kilo'       => 'kg',
        'gramm'      => 'g',
        'gr'         => 'g',
        'milligramm' => 'mg',
        'liter'      => 'l',
        'litre'      => 'l',
        'ltr'        => 'l',
        'milliliter' => 'ml',
        'centiliter' => 'cl',
        'zentiliter' => 'cl',
    ];

    /** Display order of a group's per-unit subtotals in total_label. */
    private const TOTAL_UNIT_ORDER = [ 'g', 'ml', self::PIECE_UNIT ];

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

            foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
                /** @var \WC_Order_Item_Product $item */
                // get_qty_refunded_for_item() is <= 0 (refund quantities are
                // stored negative), so adding it nets the refund out. A fully
                // refunded line no longer needs packing and is skipped.
                $net_qty = $item->get_quantity() + $order->get_qty_refunded_for_item( $item_id );
                if ( $net_qty <= 0 ) {
                    continue;
                }
                self::aggregate_item( $groups, $item, $net_qty, $customer_name, $shipping_method );
            }
        }

        $result = array_values( $groups );
        usort( $result, [ self::class, 'compare_groups' ] );

        foreach ( $result as &$group ) {
            $group['total_label'] = self::format_totals( $group['totals'] );
            foreach ( $group['orders'] as &$order_row ) {
                $order_row['qty_label'] = self::format_menge( $order_row );
                unset( $order_row['weight_per_unit'], $order_row['multiplier'], $order_row['raw_qty'], $order_row['calc_qty'], $order_row['unit'] );
            }
            unset( $order_row );
            unset( $group['totals'] );
        }
        unset( $group );

        return [ 'groups' => $result ];
    }

    /**
     * Sort order for groups: weighed products (mass + volume, g ≈ ml) first,
     * heaviest first; piece counts only break ties - and so order the
     * piece-only groups among themselves. Grams and pieces have no common
     * scale, so comparing them as raw numbers would be meaningless.
     */
    private static function compare_groups( array $a, array $b ): int {
        $weighed_a = ( $a['totals']['g'] ?? 0 ) + ( $a['totals']['ml'] ?? 0 );
        $weighed_b = ( $b['totals']['g'] ?? 0 ) + ( $b['totals']['ml'] ?? 0 );
        return [ $weighed_b, $b['totals'][ self::PIECE_UNIT ] ?? 0 ]
            <=> [ $weighed_a, $a['totals'][ self::PIECE_UNIT ] ?? 0 ];
    }

    /**
     * @param int|float $raw_qty Net line quantity (ordered minus refunded), > 0.
     */
    private static function aggregate_item( array &$groups, \WC_Order_Item_Product $item, $raw_qty, string $customer_name, string $shipping_method ): void {
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
                'totals' => [],
                'orders' => [],
            ];
        }

        // Each line counts in its own unit dimension ("g", "ml" or "Stück"):
        // a product sold both by weight and by piece (or with a line lacking
        // weight meta) gets one subtotal per unit, never grams + pieces.
        $unit = $weight ? $weight['unit'] : self::PIECE_UNIT;
        $qty  = $weight ? $raw_qty * $weight['val'] : $raw_qty * $multiplier;
        $groups[ $key ]['totals'][ $unit ] = ( $groups[ $key ]['totals'][ $unit ] ?? 0 ) + $qty;

        $weight_per_unit = $weight ? $weight['val'] : null;
        $found_index = null;
        foreach ( $groups[ $key ]['orders'] as $i => $existing ) {
            if ( $existing['customer_name'] === $customer_name
                && $existing['variant'] === $variant_str
                && $existing['shipping_method'] === $shipping_method
                && $existing['unit'] === $unit
                && $existing['weight_per_unit'] === $weight_per_unit
                && $existing['multiplier'] === $multiplier
            ) {
                $found_index = $i;
                break;
            }
        }

        if ( $found_index !== null ) {
            $groups[ $key ]['orders'][ $found_index ]['raw_qty']  += $raw_qty;
            $groups[ $key ]['orders'][ $found_index ]['calc_qty'] += $qty;
            return;
        }

        $groups[ $key ]['orders'][] = [
            'customer_name'   => $customer_name,
            'shipping_method' => $shipping_method,
            'variant'         => $variant_str,
            'raw_qty'         => $raw_qty,
            'calc_qty'        => $qty,
            'unit'            => $unit,
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

    /**
     * Parses the first "<number> <unit>" in a weight string, e.g. "500 g",
     * "500gr", "500 Gramm", "1,5 kg", "1.5 kg", "1 Liter", "0,25 l".
     *
     * Number formats: "1,5" and "1.5" are decimals. A dot followed by
     * exactly three digits is read as a German thousands separator only where
     * that is the plausible meaning: with small units (g/mg/ml/cl) and a
     * leading group other than "0" ("1.000 g" = 1000 g), or when a decimal
     * comma follows ("1.000,5 g"). With kg/l it stays a decimal point
     * ("0.500 kg" = 500 g, "1.250 kg" = 1,25 kg).
     */
    private static function parse_weight( ?string $str ): ?array {
        if ( $str === null || $str === '' ) {
            return null;
        }
        // /u + letter lookahead instead of \b: without it "2 Grüne …" matched
        // "Gr" as grams, because \b sees the multibyte "ü" as a non-word byte.
        $pattern = '/(?<!\d)(?<!\d[.,])(\d{1,3}(?:\.\d{3})+(?:,\d+)?(?!\d)|\d+(?:[.,]\d+)?)\s*'
            . '(kilogramm|kilo|kg|milligramm|mg|gramm|gr|g|milliliter|ml|centiliter|zentiliter|cl|liter|litre|ltr|l)(?![\p{L}\d])/iu';
        if ( ! preg_match( $pattern, $str, $m ) ) {
            return null;
        }

        $unit = strtolower( $m[2] );
        $unit = self::UNIT_ALIASES[ $unit ] ?? $unit;
        if ( ! isset( self::UNIT_CONVERSIONS[ $unit ] ) ) {
            return null;
        }

        $number = $m[1];
        if ( preg_match( '/^\d{1,3}(?:\.\d{3})+(?:,\d+)?$/', $number ) ) {
            $has_comma    = strpos( $number, ',' ) !== false;
            $small_unit   = in_array( $unit, [ 'g', 'mg', 'ml', 'cl' ], true );
            $leading_zero = strpos( $number, '0.' ) === 0;
            if ( $has_comma || ( $small_unit && ! $leading_zero ) ) {
                $number = str_replace( '.', '', $number );
            }
        }
        $val = (float) str_replace( ',', '.', $number );

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
        return rtrim( rtrim( number_format( $val, 3, ',', '' ), '0' ), ',' );
    }

    /**
     * $val is nullable defensively (weight_per_unit is null for piece rows);
     * treated as 0 rather than left to fatal on a null arithmetic op.
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

    /**
     * Joins a group's per-unit subtotals, e.g. "3,5 kg" or "3,5 kg + 4 Stück".
     *
     * @param array<string, int|float> $totals Keyed by "g" / "ml" / "Stück".
     */
    private static function format_totals( array $totals ): string {
        $parts = [];
        foreach ( self::TOTAL_UNIT_ORDER as $unit ) {
            if ( isset( $totals[ $unit ] ) ) {
                $parts[] = self::format_weight( (float) $totals[ $unit ], $unit );
            }
        }
        return implode( ' + ', $parts );
    }

    private static function format_menge( array $order_row ): string {
        $unit      = $order_row['unit'];
        $raw_label = self::format_number( (float) $order_row['raw_qty'] );

        if ( $unit === self::PIECE_UNIT ) {
            if ( $order_row['multiplier'] > 1 ) {
                return sprintf(
                    '%s × %d Stück = %s Stück',
                    $raw_label,
                    $order_row['multiplier'],
                    self::format_number( (float) $order_row['calc_qty'] )
                );
            }
            return self::format_number( (float) $order_row['calc_qty'] ) . ' Stück';
        }

        if ( abs( $order_row['raw_qty'] - 1 ) >= 0.0001 ) {
            return sprintf(
                '%s × %s = %s',
                $raw_label,
                self::format_weight( $order_row['weight_per_unit'], $unit ),
                self::format_weight( (float) $order_row['calc_qty'], $unit )
            );
        }

        return self::format_weight( (float) $order_row['calc_qty'], $unit );
    }
}
