<?php
/**
 * @package WooImportImgFix
 */
/*
Plugin Name: WooCommerce Import Suite Image Fix
Plugin URI: http://www.academe.co.uk/
Description: Fix duplicate images in products and variants on import through the WooCommerce CSV Import Suite.
Version: 1.2.2
Author: Academe Computing
Author URI: http://www.academe.co.uk/
License: GPLv2 or later
*/


class WooImportImgFix
{
    private static $instance = null;

    // The page slug name for all the admin functions.
    public $page_slug = 'woocommerce_import_suite_image_fix';

    public $image_signature_meta_key = '_image_signature';
    public $product_gallery_meta_key = '_product_image_gallery';
    public $featured_image_meta_key = '_thumbnail_id';

    // Leave false to go through the motions, but not actually update.
    // Set to true to write updates back to the database.
    public $do_updates = false;

    /**
     * Return the singleton.
     * PHP5.2 compatible version.
     */

    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Check if the user has permission to access the pages.
     * The plugin constructor is too early to do this.
     */

    public function isAdmin()
    {
        return current_user_can('manage_options');
    }

    /**
     * Plugin constructor.
     * Register all the hooks.
     */

    public function __construct()
    {
        // Only active for administrators and in the adminstration pages.
        // We can only detect the admin pages at this stage.

        if (is_admin()) {
            add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        }
    }

    /**
     * Admin Menu
     */

    public function admin_menu()
    {
        if (!$this->isAdmin()) return;

        $page = add_submenu_page(
            'woocommerce',
            __( 'Import Suite Image Fix', 'wc_import_image_fix' ),
            __( 'Import Suite Image Fix', 'wc_csv_import' ),
            apply_filters( 'woocommerce_csv_product_role', 'manage_woocommerce' ),
            $this->page_slug,
            array($this, 'admin_page')
        );
    }

    /**
     * Admin Init
     */

    public function admin_init()
    {
        // Some actions that product output files, so need to be performed early.

        if (!empty($_GET['action']) && !empty($_GET['page']) && $_GET['page'] == $this->page_slug) {
            switch ($_GET['action']) {
                case "export" :
                    //$this->product_exporter( 'product' );
                    break;
                case "export_variations" :
                    //$this->product_exporter( 'product_variation' );
                    break;
            }
        }
    }

    /**
     * Admin Page
     */

    public function admin_page()
    {
        if (!$this->isAdmin()) return;

        $action = (!empty($_GET['action']) ? $_GET['action'] : '');
        $fix_count = (!empty($_GET['fix_count']) ? $_GET['fix_count'] : 1);
        $do_updates = (!empty($_GET['do_updates']) ? $_GET['do_updates'] : 0);

        if (!is_numeric($fix_count)) $fix_count = 1;

        echo "<h2>Fix Duplicate Imported Images</h2>";

        echo "<p>";
        echo "This page will find duplicate images that are used against products in the product galleries, featured image or variations.";
        echo "The duplicates can then be taken off the products and replaced by the first (earliest) image in each duplicate set.";
        echo "</p>";

        echo "<p>";
        echo 'Preparation (run this first to find new images): <a href="' . admin_url('admin.php?page=' . $this->page_slug . '&action=scan_sigatures') . '">Scan and record image signatures</a>';
        echo "</p>";

        echo "<form action='" . admin_url('admin.php') . "' method='get'>";

        echo "</p>";
        echo "<input type='hidden' name='page' value='" . $this->page_slug . "' />";
        echo "<input type='hidden' name='action' value='relink_dups' />";

        echo "<label for='do_updates'>";
        echo "<input type='checkbox' name='do_updates' id='do_updates' value='1' " . (!empty($do_updates) ? 'checked="checked"' : '') . " />";
        echo " Update the database (will emulate the updates without any changes if not checked)</label>";
        echo "</p>";

        echo "<p>";
        echo "<label for='fix_count'>Number of images to process for duplicates (zero will process all)";
        echo "<input type='text' name='fix_count' id='fix_count' value='" . $fix_count . "' />";
        echo "</label>";
        echo "</p>";

        echo "<p>";
        echo "<input type='submit' value='Go!'>";
        echo "</p>";

        echo "</form>";

        switch ($action) {
            case 'scan_sigatures':
                $this->scan_signatures();
                break;
            case 'relink_dups':
                // Assume this is a test, unless the "do updates" box is ticcked.
                if (!empty($do_updates)) $this->do_updates = true;

                $this->relink_dups($fix_count);
                break;
        }
    }

    /**
     * Scan images and record their unique signatures.
     * This is what we use to tell if two images are identical, ragardless of what they
     * are called and where they are locacted.
     */

    public function scan_signatures()
    {
        echo "<hr /><h2>Scanning Images for Signatures</h2>";

        // Set posts_per_page=-1 to fetch all (defaults to 5 if not specified). nopaging=true does
        // the same thing, so belt and braces here.
        // Order by the signature, so they are grouped by identical images, and then date, so the earliest
        // is first.

        // Cannot order by signature field here, as the ordering does an inner join and so
        // does not select images that don't have a signature yet.

        $attachment_ids = get_posts(array(
            'post_type' => 'attachment',
            'fields' => 'ids',
            'post_mime_type' => 'image',
            'nopaging' => true,
            'posts_per_page' => -1,
            'post_status' => 'any',
            //'orderby' => 'meta_value date',
            //'meta_key' => $this->image_signature_meta_key,
            //'order' => 'asc',
        ));

        echo count($attachment_ids) . " images found<br />";

        $upload_dir = wp_upload_dir();
        $last_signature = '';

        foreach ($attachment_ids as $attachment_id) {
            //$src = wp_get_attachment_image_src($attachment_id, 'full');
            //$img = wp_get_attachment_image($attachment_id, 'full');

            $image_meta = wp_get_attachment_metadata($attachment_id, true);

            if ($image_meta) {
                $signature = get_post_meta($attachment_id, $this->image_signature_meta_key, true);
                $url = wp_get_attachment_url($attachment_id);

                if (!empty($signature)) {
                    // We already have a signature for this image.
                    echo "<a href='$url'>$image_meta[file]</a> = $signature"
                        . ($signature == $last_signature ? ' <strong>[DUPLICATE]</strong>' : '')
                        . "<br />";
                } else {
                    // No signature yet, so get it from the file content.
                    $image_path = $upload_dir['basedir'] . '/' . $image_meta['file'];

                    if (is_readable($image_path)) {
                        $signature = md5_file($image_path);
                        update_post_meta($attachment_id, $this->image_signature_meta_key, $signature);
                    }

                    echo "<strong>NEW:</strong> <a href='$url'>$image_meta[file]</a> = $signature"
                        . ($signature == $last_signature ? ' <strong>[DUPLICATE]</strong>' : '')
                        . "<br />";
                }
            } else {
                echo "No metadata for image #$attachment_id <br />";
                $signature = '';
            }

            $last_signature = $signature;
        }
    }

    /**
     * Relink all the duplicates.
     * Only relink the duplicates that are attached to products and variations.
     */

    public function relink_dups($fix_count = 0)
    {
        echo "<hr /><h2>Relinking Duplicates</h2>";

        // Start by getting a list of the duplicates for relinking.
        $relink_list = $this->getDupList($fix_count);

        if (empty($relink_list)) {
            echo "<p>No duplicate images to relink.</p>";
        } else {
            // Loop for each duplicate group.
            foreach($relink_list as $master_id => $duplicate_ids) {
                // First make sure the master is linked to a product or variation.
                $master = get_post($master_id);

                $master_parent = get_post($master->post_parent);
                $parent_type = $master_parent->post_type;
                $link_url = get_attachment_link($master->ID);
                $raw_url = wp_get_attachment_url($master->ID);

                echo "Master image #$master_id '"
                    . "<a href='$link_url'>"
                    . $master->post_title
                    . "</a>' <a href='$raw_url'>raw</a> <a href='$link_url'>link</a><br />";

                if ($parent_type != 'product' && $parent_type != 'product_variation') {
                    echo "* Image is not attached to a product or variation. Skipping.<br />";

                    // Skip this one.
                    continue;
                }

                echo "* Master image has ".count($duplicate_ids)." duplicates (#".implode(', #', $duplicate_ids).")<br />";

                // Now go through each duplicate, and find out where it is used.
                // Each use needs to be switched over to the master.
                foreach($duplicate_ids as $duplicate_id) {
                    // Update where it is used in a product gallery.
                    $gallery_count = $this->switchGalleryDuplicate($master_id, $duplicate_id);
                    if ($gallery_count > 0) echo "* Relinking image #$duplicate_id in $gallery_count galleries<br />";

                    // Update where it is used in a product thumbnail (featured image).
                    $product_count = $this->switchProductDuplicate($master_id, $duplicate_id);
                    if ($product_count > 0) echo "* Relinking image #$duplicate_id in $product_count products<br />";

                    // Update where it is used in a product variation thumbnail (featured image).
                    $variant_count = $this->switchVariantDuplicate($master_id, $duplicate_id);
                    if ($variant_count > 0) echo "* Relinking image #$duplicate_id in $variant_count variations<br />";

                    // Now unlink the duplicate image from the product or variation it is linked to.
                    // This is how we spot it in the gallery as no longer used.
                    // TODO: Only do this if the image is not in the body of the (or any other) product. It is probably not
                    // going to be simple finding this out. Probably a search with the URI of the image, except
                    // we don't know what sizes may be linked to.

                    if ($this->do_updates) {
                        wp_update_post(array(
                            'ID' => $duplicate_id,
                            'post_parent' => 0,
                        ));
                    }
                }

                echo "<br />";
            }
        }
    }

    /**
     * Switch or remap any instance of the duplicate_id to the master_id in the main product variant thumbnails.
     */

    public function switchVariantDuplicate($master_id, $duplicate_id, $post_type = 'product_variation')
    {
        $remap_count = 0;

        // Find all product variants that have the duplicate image as a featured image.

        $products = get_posts(array(
            'post_type' => $post_type,
            'meta_query' => array(
                array(
                    'key' => $this->featured_image_meta_key,
                    'value' => (string)$duplicate_id,
                    'compare' => '='
                ),
            ),
        ));

        foreach($products as $product) {
            if ($this->do_updates) update_post_meta($product->ID, $this->featured_image_meta_key, $master_id);
            $remap_count++;
        }

        return $remap_count;
    }

    /**
     * Switch or remap any instance of the duplicate_id to the master_id in the main product thumbnails.
     */

    public function switchProductDuplicate($master_id, $duplicate_id)
    {
        return $this->switchVariantDuplicate($master_id, $duplicate_id, 'product');
    }

    /**
     * Switch any instance of the duplicate_id to the master_id in the product galleries.
     */

    public function switchGalleryDuplicate($master_id, $duplicate_id)
    {
        $remap_count = 0;

        // There is no reverse looking of products that use this image. We need
        // to go through each product and check the gallery lists.
        // We can cut the list of products down with a search of the duplicate ID
        // in the gallery list metafield.

        $products = get_posts(array(
            'post_type' => 'product',
            'meta_query' => array(
                array(
                    'key' => $this->product_gallery_meta_key,
                    'value' => (string)$duplicate_id,
                    'compare' => 'LIKE'
                ),
            )
        ));

        foreach($products as $product) {
            $gallery = get_post_meta($product->ID, $this->product_gallery_meta_key, true);
            $gallery = explode(',', $gallery);

            $replace_count = 0;
            while(true) {
                // Yes, this duplicate is used in this gallery.
                // Replace it in the array.
                // The duplicate image could be in multiple times, so remove ALL instances (hence the loop
                // to keep trying until no more matches - a hacky way to do it, but no time to refactor at this point).

                $key = array_search((string)$duplicate_id, $gallery);
                if ($key !== false) {
                    // Update the gallery image then save it back to the product.
                    // Check in case the master is already in the gallery. We only want it in once.
                    if (in_array((string)$master_id, $gallery)) {
                        // Yes, already in. Just remove the duplicate.
                        unset($gallery[$key]);
                    } else {
                        // Replace the duplicate with the master.
                        $gallery[$key] = $master_id;
                    }

                    $replace_count++;

                } else {
                    // Write it back only if the flag says to do so and there has been at least one change.
                    if ($replace_count && $this->do_updates) {
                        // Remove any duplictate gallery entries. The shop and WP should prevent
                        // this from happening, but it still happens. We don't want to change the
                        // order of the entries, so need to do this carefully.

                        // This may be better as a separate function, just makeing sure all images linked to all
                        // product galleries are in the gallery just once, regardless of where the images come from
                        // or where they are located.

                        $gallery_list = array();
                        foreach($gallery as $key => $value) {
                            if (isset($gallery_list[$value])) unset($gallery[$key]);
                            $gallery_list[$value] = $value;
                        }

                        $gallery = implode(',', $gallery);
                        update_post_meta($product->ID, $this->product_gallery_meta_key, $gallery);
                    }

                    break;
                }
            }

            $remap_count++;
        }

        // Return a count of the remaps made, for logging.
        return $remap_count;
    }

    /**
     * Get a list of the duplicates that need relinking.
     * We are only interested in images that are attached to a product or variation.
     * The result is an array of master_image_id => [duplicate_ids]
     */

    public function getDupList($fix_count = 0)
    {
        $result = array();

        $attachments = get_posts(array(
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'nopaging' => true,
            'posts_per_page' => -1,
            'post_status' => 'any',
            'orderby' => 'meta_value date',
            'meta_key' => $this->image_signature_meta_key,
            'order' => 'asc',
        ));

        $last_signature = '';
        $duplicates = array();
        $master_id = 0;
        $group_count = 0;

        foreach($attachments as $attachment) {
            // If there is no parent, then assume we have already processed this image.
            if (empty($attachment->post_parent)) continue;

            // If there is no metadata, then we can not do much, so skip it.
            $image_meta = wp_get_attachment_metadata($attachment->ID, true);
            if (empty($image_meta)) continue;

            // Get the signature. If there is no signature, then we cannot do a
            // duplicate check on it.
            $signature = get_post_meta($attachment->ID, $this->image_signature_meta_key, true);
            if (empty($signature)) continue;

            // Check it is attached to a post or variation.
            $parent_meta = get_post($attachment->post_parent);
            if ($parent_meta->post_type != 'product' && $parent_meta->post_type != 'product_variation') continue;

            if ($last_signature == $signature) {
                // Duplicate (same signature as the last attachment).
                $duplicates[] = $attachment->ID;
            } else {
                // Not the same signature as the last. There may be some dups to return,
                // so check that out.
                if (!empty($duplicates)) {
                    $result[$master_id] = $duplicates;
                    $duplicates = array();

                    // See if we have enough duplicate groups yet.
                    $group_count++;
                    if (!empty($fix_count) && $group_count >= $fix_count) {
                        // We have got enough groups now - break out the loop.
                        break;
                    }
                }

                $master_id = $attachment->ID;
            }

            $last_signature = $signature;
        }

        // There may be a group of dups right at the end.
        if (!empty($duplicates)) {
            $result[$master_id] = $duplicates;
        }


        return $result;
    }
}

// Initialise the class.
WooImportImgFix::getInstance();


