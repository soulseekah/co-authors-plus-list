# A Co-Authors Plus `get_authors` Implementation

This is a function that provides a `get_authors` implementation for the [Co-Authors Plus](https://wordpress.org/plugins/co-authors-plus/) plugin, which allows developers to pull in both regular and guest authors.

Supports custom fields (like the ones ACF creates). Does everything in **just one SQL query** (unless you have ACF images, then it's two).

## Installation

Drop into your `mu-plugins` or `plugins` directory and activate, or just include within your theme's function.php. Lots of ways to go about this.

## Usage

```php
$authors = coauthors_plus_get_authors();
```

## Advanced usage

Coming soon. Meanwhile, read the [docblock](https://github.com/soulseekah/co-authors-plus-list/blob/master/co-authors-plus-list.php#L11) ;)
