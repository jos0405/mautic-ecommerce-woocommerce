<?php

declare(strict_types=1);

namespace MauticEcommerceWooCommerce;

/**
 * Keeps Mautic's product catalog in sync: on save, stock change and deletion, and in bulk.
 */
final class ProductSync
{
    public const BATCH_SIZE = 100;

    public function __construct(
        private Settings $settings,
        private Queue $queue,
    ) {
    }

    public function register(): void
    {
        add_filter('mautic_ecommerce_build_product', [$this, 'buildJob'], 10, 2);

        if (!$this->settings->isEnabled('products')) {
            return;
        }

        add_action('woocommerce_new_product', [$this, 'queueProduct']);
        add_action('woocommerce_update_product', [$this, 'queueProduct']);
        add_action('woocommerce_update_product_variation', [$this, 'queueProduct']);
        add_action('woocommerce_product_set_stock', [$this, 'queueProductObject']);
        add_action('woocommerce_variation_set_stock', [$this, 'queueProductObject']);
        add_action('wp_trash_post', [$this, 'queueDeletion']);
        add_action('before_delete_post', [$this, 'queueDeletion']);
    }

    public function queueProduct(int $productId): void
    {
        $this->queue->push('product', ['id' => $this->parentId($productId)]);
    }

    public function queueProductObject(\WC_Product $product): void
    {
        $this->queueProduct($product->get_id());
    }

    public function queueDeletion(int $postId): void
    {
        if ('product' === get_post_type($postId)) {
            $this->queue->push('product', ['id' => $postId, 'deleted' => true]);
        }
    }

    /**
     * @param array<string, scalar> $args
     *
     * @return array<string, mixed>|null
     */
    public function buildJob(mixed $payload, array $args): ?array
    {
        $id      = (int) ($args['id'] ?? 0);
        $product = empty($args['deleted']) ? wc_get_product($id) : null;

        // Deleted, trashed or gone by the time the job runs.
        if (!$product instanceof \WC_Product || 'trash' === $product->get_status()) {
            return ['_endpoint' => 'product', 'store' => $this->settings->getStore(), 'deleted' => [(string) $id]];
        }

        return ['_endpoint' => 'product', 'store' => $this->settings->getStore()] + $this->build($product);
    }

    /**
     * @return array<string, mixed> one product in the Ecommerce Connector's standard format
     */
    public function build(\WC_Product $product): array
    {
        $imageId  = (int) $product->get_image_id();
        $modified = $product->get_date_modified();

        return [
            'id'             => (string) $product->get_id(),
            'sku'            => $product->get_sku(),
            'name'           => html_entity_decode(wp_strip_all_tags($product->get_name()), ENT_QUOTES),
            'url'            => get_permalink($product->get_id()),
            'image_url'      => $imageId > 0 ? (wp_get_attachment_image_url($imageId, 'woocommerce_single') ?: null) : null,
            // Prices as the shopper sees them, including tax where the shop shows it so.
            'price'          => '' !== $product->get_price() ? (string) wc_get_price_to_display($product) : null,
            'regular_price'  => '' !== $product->get_regular_price() ? (string) wc_get_price_to_display($product, ['price' => $product->get_regular_price()]) : null,
            'sale_price'     => '' !== $product->get_sale_price() ? (string) wc_get_price_to_display($product, ['price' => $product->get_sale_price()]) : null,
            'currency'       => get_woocommerce_currency(),
            'category'       => self::mainCategory($product->get_id()),
            'categories'     => self::categoryNames($product->get_id()),
            'variation_ids'  => array_map('strval', $product->get_children()),
            'stock_status'   => $product->get_stock_status(),
            'stock_quantity' => $product->managing_stock() ? (int) $product->get_stock_quantity() : null,
            'status'         => $product->get_status(),
            'updated_at'     => $modified ? $modified->format(DATE_ATOM) : null,
        ];
    }

    /**
     * Sends every product, in batches. For the first sync and to repair a catalog.
     *
     * @param callable(int): void $progress
     */
    public function syncAll(Client $client, callable $progress): int
    {
        $sent = 0;
        $page = 1;

        do {
            $batch = $this->syncPage($client, $page++);
            if ($batch['sent'] > 0) {
                $sent += $batch['sent'];
                $progress($sent);
            }
        } while (self::BATCH_SIZE === $batch['fetched']);

        return $sent;
    }

    /**
     * Sends one page of BATCH_SIZE products in one request; the bulk sync runs page by page.
     *
     * @return array{fetched: int, sent: int} fetched below BATCH_SIZE means this was the last page
     *
     * @throws \RuntimeException when Mautic cannot be reached
     */
    public function syncPage(Client $client, int $page): array
    {
        $ids = wc_get_products([
            'type'    => ['simple', 'variable', 'grouped', 'external'],
            'status'  => ['publish', 'private', 'draft', 'pending'],
            'limit'   => self::BATCH_SIZE,
            'page'    => max(1, $page),
            'return'  => 'ids',
            'orderby' => 'ID',
            'order'   => 'ASC',
        ]);

        $products = [];
        foreach ($ids as $id) {
            $product = wc_get_product($id);
            if ($product instanceof \WC_Product) {
                $products[] = $this->build($product);
            }
        }

        if ([] !== $products) {
            $client->send('product', ['store' => $this->settings->getStore(), 'products' => $products]);
        }

        return ['fetched' => count($ids), 'sent' => count($products)];
    }

    /**
     * The category order lines report: the SEO plugin's primary category if one is set,
     * otherwise the first real category. Filterable for shops with their own rule.
     */
    public static function mainCategory(int $productId): ?string
    {
        $productId = self::topLevelId($productId);
        $primaryId = (int) (get_post_meta($productId, '_yoast_wpseo_primary_product_cat', true) ?: get_post_meta($productId, 'rank_math_primary_product_cat', true));
        $term      = $primaryId > 0 ? get_term($primaryId, 'product_cat') : null;
        $name      = $term instanceof \WP_Term ? $term->name : null;

        if (null === $name) {
            foreach (wp_get_post_terms($productId, 'product_cat') as $category) {
                if ($category instanceof \WP_Term && 'uncategorized' !== $category->slug) {
                    $name = $category->name;
                    break;
                }
            }
        }

        return apply_filters('mautic_ecommerce_main_category', null !== $name ? html_entity_decode($name) : null, $productId);
    }

    /**
     * @return list<string>
     */
    public static function categoryNames(int $productId): array
    {
        $names = [];
        foreach (wp_get_post_terms(self::topLevelId($productId), 'product_cat') as $category) {
            if ($category instanceof \WP_Term) {
                $names[] = html_entity_decode($category->name);
            }
        }

        return $names;
    }

    private function parentId(int $productId): int
    {
        return self::topLevelId($productId);
    }

    private static function topLevelId(int $productId): int
    {
        $parent = (int) wp_get_post_parent_id($productId);

        return $parent > 0 && 'product_variation' === get_post_type($productId) ? $parent : $productId;
    }
}
