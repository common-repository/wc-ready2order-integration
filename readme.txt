=== Integration of ready2order with WooCommerce ===
Contributors: BjornTech
Tags: ready2order pos
Requires at least: 4.9
Tested up to: 6.6
Requires PHP: 7.0
Stable tag: 2.1.6
License: GPL-3.0
License URI: bjorntech.se/ready2order

Integration of ready2order with WooCommerce

== Description ==

The Integration of [ready2order](https://ready2order.com/) with WooCommerce lets you link information about the products and their stock levels between WooCommerce and ready2order. You can set up the plugin to either sync the information in batches or in realtime. Here is some examples of what the plugin allows you to do:

- Create ready2order products from WooCommerce products.
- Create WooCommerce products from ready2order products.
- Realtime update selected data on products in ready2order from changes in WooCommerce.
- Realtime update selected data on products in WooCommerce from changes in ready2order.
- Synchronize stock levels between WooCommerce and ready2order.
- (Beta) Optionally create ready2order invoices based on WooCommerce orders.

Have a look in our [knowledgebase](https://bjorntech.com/article-categories/ready2order?utm_source=wp-ready2order&utm_medium=plugin&utm_campaign=product) or contact us at hello@bjorntech.com if you do have questionss

== Changelog ==
= 2.1.6
* Verified to work with WooCommerce 9.3 and Wordpress 6.6
* New: Added option to set ready2order invoice tax rates to 0
* New: Added option to delete products in WooCommerce if the corresponding product does not exist in ready2order
* Fix: When doing bulk actions - the redirect back to the orders and products page does not work properly on some configurations
* Fix: Stock import sometimes not working properly
= 2.1.5
* Verified to work with WooCommerce 8.1 and Wordpress 6.3
= 2.1.4
* Verified to work with WooCommerce 7.9
* WC High-Performance Order Storage compatibility declaration
= 2.1.3
* Hotfix: Removed confusing message for new users
= 2.1.2
* Verified to work with WooCommerce 7.6 and Wordpress 6.2
* New: Added Getting started guide for all users
= 2.1.1
* Verified to work with WooCommerce 7.4
* New: It is now possible to export sale prices to ready2order as well as normal prices
* Fix: ready2order purchases sometimes not generating stock changes in WooCommerce
* Fix: Product group filter not working properly when doing manual imports
= 2.1.0
* Verified to work with WooCommerce 7.3
* Major rehaul of the syncing logic - all syncs should now be more stable, reliable and quick
* New: Added option to exclude products from certain product groups during import
* New: Added option for compensating for no CRON jobs running on the server
* New: Added option to ignore product group hierarchies during import
* New: Added option to listen to more product changing events in WooCommerce
* Fix: Include and exclude filters for product groups was not aware of parents
* Fix: Ratelimiter sometimes faster than intended
= 2.0.3
* Fix: Multiple ready2order webhooks with the same content not handled properly
* Fix: Include product groups filter not respected when doing a manual import
* Fix: Adjust stocklevel from order option sometimes interfering with Stock option from Export products
= 2.0.2
* Verified to work with WooCommerce 7.1 and Wordpress 6.1
* New: Added import filter for product groups
* New: Added import filter to exclude products containing certain strings
* New: Added support for the WooCommerce Ultimate Gift Card plugin
* Fix: Stock being reduced twice when using the Stocklevel option in Export products and the Invoice creation option inside the plugin
* Fix: When using ready2order variable products - the parent product would be assigned random stocklevels
= 2.0.1
* Verified to work with WooCommerce 6.9
* New: Added setting to create ready2order invoices from WooCommerce orders that are unpaid
* Fix: License valid to time sometimes being displayed improperly
* Fix: ready2order will sometimes still be created even with setting turned off
* Dev: Increased performance of ready2order invoice settings
= 2.0.0
* Verified to work with WooCommerce 6.8
* New: (Beta) Added functionality to create ready2order invoices from WooCommerce orders
* New: (Beta) Added option to create ready2order variable products from WooCommerce variable products
* New: Added option to create additional identifiers for variants that are synced from WooCommerce to ready2order
* Fix: Products from all languages were sometimes created in ready2order even when using the WPML/Polylang option
= 1.9.1
* Verified to work with WooCommerce 6.6
* New: Added option to optionally sync catogories
* New: Added option to optionally sync products with different statuses
* New: Added option to sync SKUs
* Fix: Unable to remove admin notices
= 1.9.0
* Verified to work with Wordpress 6.0 and WooCommerce 6.5
* New: Added WPML/Polylang support to product exports to ready2order
* New: Added option to sync only default product group
* New: Added option to optionally sync categories over to ready2order
* New: Added option to skip syncing specific WooCommerce products
* Fix: Product group with too long of a description throws error
* Fix: Unable to remove products from the action scheduler when syncing to ready2order
* Fix: Errors when product group has been deleted in ready2order
* Fix: Errors when product has been deleted in ready2order
= 1.8.3
* Verified to work with WooCommerce 6.2
* Fix: Categories sometimes not syncing
* Added support for new ready2order login flow
= 1.8.2
* Verified to work with Wordpress 5.9
* Fix: Removed ready2order disconnect button
= 1.8.1
* Verified to work with WooCommerce 6.1
* Fix: Product groups are not picked up as categories in WooCommerce
= 1.8.0
* Verified to work with WooCommerce 5.9
* Product now out of beta
* New: Added option for syncing variable products
= 1.7.0
* Verified to work with WooCommerce 5.8
* Fix: Rewrite of the change stocklevel from order function that was not working in all cases.
= 1.6.3
* Beta period prolonged to end of November
* Verified to work with WooCommerce 5.7
= 1.6.2
* Fix: Faulty call to notice-function caused a Critical error in rare cases.
= 1.6.1
* Verified to work with Wordpress 5.8 and WooCommerce 5.5
= 1.6.0
* New: Stocklevel can now be set from the Export products menu.
* Fix: A fatal error was thrown if not connected when selecting the "Export products" menu.
= 1.5.1
* Fix: Then plugin did throw a fatal error in some PHP versions.
* Fix: Manual import failed if ready2order had a large ()>2500) number of products.
= 1.5.0
* New: Added beta-message
* Fix: The import failed to import all products when having a large number of products in the POS
= 1.4.0
* Fix: The API-connection with ready2order does in some cases crash and causes a fatal error.
= 1.3.0
* Initial release on Wordpress

