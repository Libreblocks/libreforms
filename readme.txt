=== LibreForms ===
Contributors: libreblocks
Tags: form, contact form, front-end submission, form builder, webhook
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.0
Requires Plugins: libreblocks-core, libreforge
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Forms for contact, sign-ups and front-end submissions, built with the same field editor as LibreForge.

== Description ==

Choose the fields, then what happens after sending. Each form can run several actions, in order:

* Save the entry: answers appear in LibreForms -> Entries, with CSV export (opens in Excel and Google Sheets).
* Send an e-mail: to you, the team or the visitor, with the answers in a table or your own text with {{form.value|field}}.
* Create a post: front-end submission into any Post Type, filling the Meta Box fields with the same names and the featured image.
* Send to a webhook: the answers as JSON to n8n, Zapier, Make or a CRM.
* Go to another page: a thank-you page after sending.

Also:

* 15 field types, widths (two fields side by side), required fields and conditional fields ("show when...").
* File uploads, checked by type and size.
* Spam protection without captchas: hidden trap field, minimum time to fill in and a limit per hour per visitor.
* Sends without reloading the page, and still works without JavaScript.
* Gutenberg block "Form", Elementor widget "Form" with style controls, and the shortcode `[libreforms id="12"]`.

Requires LibreBlocks Core and LibreForge.

== Changelog ==

= 0.2.0 =
* Other plugins can add field types and actions to forms (used by LibreStay: "Check-in and check-out" field and "Create booking" action).
* Forms know the item of the page where they are placed; e-mails can use {{object.*}} tokens.
* E-mails can list submission details the visitor does not type: date, time, page URL, item, user, browser, IP and form name (you choose which). Also as {{form.meta|...}} tokens and in webhooks.

= 0.1.0 =
* First release: form builder, actions (entry, e-mail, create post, webhook, redirect), entries screen with CSV export, block, Elementor widget and shortcode.
