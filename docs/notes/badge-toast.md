# Badge-Toast Notification System

Ersetzt Browser-`alert()`, WC-Notices für entfernte Produkte und Mindestbestellwert-Hinweise durch eine einheitliche, vom Badge ausgehende Toast-Notification.

## Ziel

- Kein `alert()`, keine roten WC-Notices außerhalb des Carts
- Alle Plugin-Hinweise kommen aus einer Quelle: dem Badge
- Auto-dismiss, nicht aufdringlich, passt zum Plugin-Design

## Anwendungsfälle

1. **Produkt entfernt** — Kunde kommt auf eine Seite nach `remove_excluded_cart_items()`: "Folgende Produkte wurden entfernt: X, Y."
2. **Redirect von Produktseite** — `?plz_blocked=1` in der URL: "Dieses Produkt ist im Postversand nicht verfügbar."
3. **Mindestbestellwert** — Modus erfordert Minimum, Cart-Summe reicht nicht: "Mindestbestellwert für Postversand: €X. Noch €Y fehlen."

## PHP-Änderungen (`wc-plz-filter.php`)

### `remove_excluded_cart_items()`
Statt `wc_add_notice()` die Meldung in der WC-Session speichern:
```php
WC()->session->set( 'wc_plz_notification', [
    'message' => 'Folgende Produkte wurden entfernt: ' . implode( ', ', $removed ) . '.',
    'type'    => 'info',
] );
```

### `maybe_show_blocked_alert()`
Komplett entfernen — Hook und Methode. Wird durch JS übernommen.

### Neue Hilfsmethode `get_pending_notification()`
Session-Notification einmalig auslesen und danach löschen:
```php
private function get_pending_notification(): array {
    if ( ! function_exists( 'WC' ) || ! WC()->session ) {
        return [];
    }
    $note = WC()->session->get( 'wc_plz_notification', [] );
    if ( ! empty( $note ) ) {
        WC()->session->set( 'wc_plz_notification', [] );
    }
    return is_array( $note ) ? $note : [];
}
```

### `enqueue()` / `wp_localize_script`
`D.notification` ergänzen:
```php
'notification' => $this->get_pending_notification(),
```

### Mindestbestellwert (noch nicht implementiert)
Neue Settings-Felder `min_order_local` und `min_order_post` (0 = deaktiviert).
Prüfung in `woocommerce_check_cart_items` oder eigenem Hook — bei Unterschreitung ebenfalls in Session schreiben statt WC-Notice.

## JS-Änderungen (`assets/js/plz-popup.js`)

### Init
```js
// Pending notification aus PHP (z.B. entfernte Produkte)
if (D.notification && D.notification.message) {
    showBadgeToast(D.notification.message);
}
// Redirect von Produktseite
if (/[?&]plz_blocked=1/.test(location.search)) {
    showBadgeToast('Dieses Produkt ist im Postversand nicht verfügbar.');
    // Query-Param aus URL entfernen ohne Reload
    var url = location.href.replace(/([?&])plz_blocked=1(&|$)/, function(_, pre, post) {
        return post ? pre : '';
    });
    history.replaceState(null, '', url);
}
```

### `showBadgeToast(message)`
```js
function showBadgeToast(message) {
    var badge = document.getElementById('wc-plz-badge');
    if (!badge) return;

    var toast = document.createElement('div');
    toast.className = 'wc-plz-toast';
    toast.innerHTML = '<span>' + message + '</span>'
        + '<button class="wc-plz-toast__close" aria-label="Schließen">✕</button>';

    document.body.appendChild(toast);

    // Position relativ zum Badge berechnen
    positionToast(toast, badge);

    // Einblenden
    requestAnimationFrame(function() { toast.classList.add('wc-plz-toast--visible'); });

    // Auto-dismiss nach 6s
    var timer = setTimeout(function() { dismissToast(toast); }, 6000);

    toast.querySelector('.wc-plz-toast__close').addEventListener('click', function() {
        clearTimeout(timer);
        dismissToast(toast);
    });
}

function positionToast(toast, badge) {
    // Badge-Position aus Klasse auslesen und Toast gegenüber positionieren
    // Klassen: wc-plz-badge--bottom-right, --bottom-left, --top-right, --top-left, --left-center, --right-center
    var pos = badge.className.match(/wc-plz-badge--([a-z-]+)/);
    toast.setAttribute('data-pos', pos ? pos[1] : 'bottom-right');
}

function dismissToast(toast) {
    toast.classList.remove('wc-plz-toast--visible');
    toast.addEventListener('transitionend', function() { toast.remove(); }, { once: true });
}
```

## CSS-Änderungen (`assets/css/plz-popup.css`)

```css
.wc-plz-toast {
    position: fixed;
    z-index: 99999;
    max-width: 300px;
    background: #fff;
    border-left: 4px solid var(--plz-color, #cc0000);
    border-radius: 4px;
    box-shadow: 0 4px 16px rgba(0,0,0,.15);
    padding: 12px 36px 12px 14px;
    font-size: 14px;
    line-height: 1.4;
    opacity: 0;
    transform: translateY(12px);
    transition: opacity .25s ease, transform .25s ease;
    pointer-events: none;
}
.wc-plz-toast--visible {
    opacity: 1;
    transform: translateY(0);
    pointer-events: auto;
}
.wc-plz-toast__close {
    position: absolute;
    top: 8px;
    right: 8px;
    background: none;
    border: none;
    cursor: pointer;
    font-size: 12px;
    color: #999;
    padding: 0;
    line-height: 1;
}

/* Positionierung je Badge-Ecke */
.wc-plz-toast[data-pos="bottom-right"] { bottom: 80px; right: 16px; }
.wc-plz-toast[data-pos="bottom-left"]  { bottom: 80px; left: 16px; }
.wc-plz-toast[data-pos="top-right"]    { top: 80px; right: 16px; }
.wc-plz-toast[data-pos="top-left"]     { top: 80px; left: 16px; }
.wc-plz-toast[data-pos="left-center"]  { top: 50%; left: 80px; transform: translateX(-8px); }
.wc-plz-toast[data-pos="right-center"] { top: 50%; right: 80px; transform: translateX(8px); }
.wc-plz-toast[data-pos="left-center"].wc-plz-toast--visible,
.wc-plz-toast[data-pos="right-center"].wc-plz-toast--visible { transform: translateX(0); }
```

## Offene Punkte

- `--plz-color` CSS-Variable muss beim Enqueue aus den Settings gesetzt werden (aktuell wird Farbe als Inline-Style am Badge gesetzt)
- Mindestbestellwert-Settings noch nicht im Admin implementiert
- Toast-Positionierung bei `left-center` / `right-center` mit `top: 50%` noch nicht perfekt (Badge hat eigene Offset-Einstellungen)
