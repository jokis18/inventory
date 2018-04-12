<?php

use App\Model\Queue;
use App\Model\Shop;
use App\Model\Template;
use App\Model\Setting;

function createDrinkware(Queue $queue, Shop $shop, Template $template, Setting $setting = null)
{
    $prices = array(
        '30' => '29.99',
        '20' => '24.99'
    );

    global $s3;
    $queue->started_at = date('Y-m-d H:i:s');
    $data = $queue->data;
    $post = $data['post'];
    $image_data = array_reverse(getImages($s3, $queue->file_name));
    $designId = null;
    $imageUrls = [];
    switch($shop->myshopify_domain) {
        case 'plcwholesale.myshopify.com':
            $prices = array(
                '30' => '15',
                '20' => '14'
            );
            break;
    }
    $hasNavy = false;
    foreach ($image_data as $name) {
        $productData = pathinfo($name)['filename'];
        $specs = explode('_-_', $productData);
        $size = $specs[0];
        $color = $specs[1];
        if ($color == 'Navy') {
            $hasNavy = true;
        }
        $imageUrls[$size][$color] = $name;
    }
    $product_data = getProductSettings($shop, $post, $template, $setting);
    $product_data['options'] = array(
        array(
            'name' => "Size"
        ),
        array(
            'name' => "Color"
        )
    );


    foreach ($imageUrls as $size => $colors) {
        foreach ($colors as $color => $url) {
            $sku = $color;
            if ($color == 'Cyan') {
                $sku = 'Seafoam';
            }
            $sku = str_replace('_', ' ', $sku);
            switch ($size) {
                case '30':
                    $option1 = '30oz Tumbler';
                    $sku = getSkuFromFileName($data['file_name']).' - T30 - '.$sku;
                    break;
                case '20':
                    $option1 = '20oz Tumbler';
                    $sku = getSkuFromFileName($data['file_name']).' - T20 - '.$sku;
                    break;
            }
            $variantData = array(
                'title' => $option1. ' / '.str_replace('_', ' ', $color),
                'price' => $prices[$size],
                'option1' => $option1,
                'option2' => str_replace('_', ' ', $color),
                'weight' => '1.1',
                'weight_unit' => 'lb',
                'requires_shipping' => true,
                'inventory_management' => null,
                'inventory_policy' => 'deny',
                'sku' => $sku
            );
            if ($color == ($hasNavy ? 'Navy' : 'Black') && $size == '30') {
                $product_data['variants'] = array_merge(array($variantData), $product_data['variants']);
            } else {
                $product_data['variants'][] = $variantData;
            }
        }
    }
    $res = callShopify($shop, '/admin/products.json', 'POST', array(
        'product' => $product_data
    ));
    $imageUpdate = array();
    foreach ($res->product->variants as $variant) {
        $size = $variant->option1;
        $color = str_replace(' ', '_', $variant->option2);
        switch ($size) {
            case '30oz Tumbler':
                $size = '30';
                break;
            case '20oz Tumbler':
                $size = '20';
                break;
        }
        $image = array(
            'src' => "https://s3.amazonaws.com/shopify-product-importer/{$imageUrls[$size][$color]}",
            'variant_ids' => array($variant->id)
        );
        $imageUpdate[] = $image;
    }
    $res = callShopify($shop, "/admin/products/{$res->product->id}.json", "PUT", array(
        "product" => array(
            'id' => $res->product->id,
            'images' => $imageUpdate
        )
    ));

    $queue->finish(array($res->product->id));
    return array($res->product->id);
}
