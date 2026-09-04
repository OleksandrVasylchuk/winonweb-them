# shop-kit

The shop half of the theme, folded.

A site the studio builds does not sell anything until a design says it does.
So the theme a client activates is the theme from zero — ten templates, no
WooCommerce module, and nothing in the Site Editor about carts or checkouts to
explain away.

What is in here is the same theme's shop half, parked one directory to the
side of where WordPress looks:

```
shop-kit/templates/*.html        →  templates/*.html
shop-kit/inc/Modules/*.php       →  inc/Modules/*.php
```

`inc/Support/ShopKit.php` copies them across. The import screen calls it: when
`DesignNeeds` finds a shop in the archive — a product catalogue, a cart, an
add-to-cart button — the one button on that screen installs WooCommerce and
unfolds this folder in the same click.

Nothing here is loaded while it sits here. A file that already exists at its
destination is never overwritten, so unfolding twice is safe and a checkout
template somebody edited stays edited.

To unfold it by hand, copy the two folders over the theme root. To fold it
back, delete the copies — not the originals.
