Salsify Connect
===============

Magento Extension for the [Salsify Product Content Management system](http://www.salsify.com).


Requirements
==========================

## Magento Versions Supported

Currently Salsify Connect only supports Magento 1.7.x.

1.6.x is very likely to work, but it has not been tested.

1.5 and below definitely do _not_ work, as Salsify Connect depends on the newer Magento ImportExport API.

## PHP Versions Supported

Currently PHP 5.2, 5.3, and 5.4 are tested and supported.


Installing
==========

## Magento Connect

The easiest way to install Salsify Connect is via Magento Connect, just as you would any other extension.

In Magneto Under Settings -> Magento Connect you'll see it. You'll have to get the [Salsify Connect plugin](http://www.magentocommerce.com/magento-connect/catalog/product/view/id/17214/) from Magento Connect.

## Modman

Install [modman](https://github.com/colinmollenhour/modman).

First make sure to enable symlinks in Magento or NOTHING from Modman will work:

Under Settings -> Developer -> Template Settings -> Allow Symlinks

Go to `magento/` and:
```bash
modman init
modman clone https://github.com/avstudnitz/AvS_FastSimpleImport.git
modman clone git@github.com:salsify/mageomatic.git
```