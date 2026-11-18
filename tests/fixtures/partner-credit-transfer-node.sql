-- JinMemo MySQL transfer v1; generated 2026-11-18T00:00:00.000Z
-- DATA TRANSFER ONLY: jm_transfer_* staging tables, not a runnable PHP application schema.
-- Import into a separate database. No DROP, overwrite, auth sessions or gateway secrets.
-- Upload files are not included; copy media and original ticket files separately.
SET NAMES utf8mb4;
SET time_zone='+00:00';
CREATE TABLE `jm_transfer_product_types` (
  `id` LONGTEXT NULL,
  `slug` LONGTEXT NULL,
  `data` JSON NULL,
  `version` BIGINT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_product_types: 0 rows
CREATE TABLE `jm_transfer_destinations` (
  `id` LONGTEXT NULL,
  `slug` LONGTEXT NULL,
  `data` JSON NULL,
  `version` BIGINT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_destinations: 0 rows
CREATE TABLE `jm_transfer_suppliers` (
  `id` LONGTEXT NULL,
  `data` JSON NULL,
  `version` BIGINT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_suppliers: 0 rows
CREATE TABLE `jm_transfer_media` (
  `id` LONGTEXT NULL,
  `filename` LONGTEXT NULL,
  `alt` LONGTEXT NULL,
  `width` BIGINT NULL,
  `height` BIGINT NULL,
  `bytes` BIGINT NULL,
  `created_by` LONGTEXT NULL,
  `created_at` DATETIME(6) NULL,
  `deleted_at` DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_media: 0 rows
CREATE TABLE `jm_transfer_products` (
  `id` LONGTEXT NULL,
  `slug` LONGTEXT NULL,
  `data` JSON NULL,
  `version` BIGINT NULL,
  `updated_at` DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_products: 0 rows
CREATE TABLE `jm_transfer_pages` (
  `id` LONGTEXT NULL,
  `slug` LONGTEXT NULL,
  `data` JSON NULL,
  `version` BIGINT NULL,
  `updated_at` DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_pages: 0 rows
CREATE TABLE `jm_transfer_post_categories` (
  `name` LONGTEXT NULL,
  `english_name` LONGTEXT NULL,
  `version` BIGINT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_post_categories: 0 rows
CREATE TABLE `jm_transfer_posts` (
  `id` LONGTEXT NULL,
  `slug` LONGTEXT NULL,
  `data` JSON NULL,
  `version` BIGINT NULL,
  `created_at` DATETIME(6) NULL,
  `updated_at` DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_posts: 0 rows
CREATE TABLE `jm_transfer_post_revisions` (
  `post_id` LONGTEXT NULL,
  `version` BIGINT NULL,
  `data` JSON NULL,
  `saved_by` LONGTEXT NULL,
  `reason` LONGTEXT NULL,
  `created_at` DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_post_revisions: 0 rows
CREATE TABLE `jm_transfer_post_metrics` (
  `post_id` LONGTEXT NULL,
  `views` BIGINT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_post_metrics: 0 rows
CREATE TABLE `jm_transfer_slug_aliases` (
  `kind` LONGTEXT NULL,
  `slug` LONGTEXT NULL,
  `entity_id` LONGTEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_slug_aliases: 0 rows
CREATE TABLE `jm_transfer_policy_pages` (
  `policy_key` LONGTEXT NULL,
  `page_id` LONGTEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_policy_pages: 0 rows
CREATE TABLE `jm_transfer_orders` (
  `id` LONGTEXT NULL,
  `code` LONGTEXT NULL,
  `request_id` LONGTEXT NULL,
  `request_hash` LONGTEXT NULL,
  `access_hash` LONGTEXT NULL,
  `snapshot` JSON NULL,
  `contact` JSON NULL,
  `booking_status` LONGTEXT NULL,
  `payment_status` LONGTEXT NULL,
  `instructions` LONGTEXT NULL,
  `version` BIGINT NULL,
  `created_at` DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `jm_transfer_orders` (`id`,`code`,`request_id`,`request_hash`,`access_hash`,`snapshot`,`contact`,`booking_status`,`payment_status`,`instructions`,`version`,`created_at`) VALUES (CONVERT(X'30303030303030302d303030302d343030302d383030302d303030303030303030303131' USING utf8mb4),CONVERT(X'505249564154452d4f524445522d3131' USING utf8mb4),CONVERT(X'707269766174652d76616c7565' USING utf8mb4),CONVERT(X'707269766174652d76616c7565' USING utf8mb4),CONVERT(X'707269766174652d76616c7565' USING utf8mb4),CONVERT(X'7b22746f74616c223a313030307d' USING utf8mb4),CONVERT(X'7b7d' USING utf8mb4),CONVERT(X'434f4e4649524d4544' USING utf8mb4),CONVERT(X'435245444954' USING utf8mb4),CONVERT(X'707269766174652d76616c7565' USING utf8mb4),1,CONVERT(X'323032362d30312d30312030303a30303a30302e303030303031' USING utf8mb4));
INSERT INTO `jm_transfer_orders` (`id`,`code`,`request_id`,`request_hash`,`access_hash`,`snapshot`,`contact`,`booking_status`,`payment_status`,`instructions`,`version`,`created_at`) VALUES (CONVERT(X'30303030303030302d303030302d343030302d383030302d303030303030303030303132' USING utf8mb4),CONVERT(X'505249564154452d4f524445522d3132' USING utf8mb4),CONVERT(X'707269766174652d76616c7565' USING utf8mb4),CONVERT(X'707269766174652d76616c7565' USING utf8mb4),CONVERT(X'707269766174652d76616c7565' USING utf8mb4),CONVERT(X'7b22746f74616c223a323030307d' USING utf8mb4),CONVERT(X'7b7d' USING utf8mb4),CONVERT(X'434f4e4649524d4544' USING utf8mb4),CONVERT(X'435245444954' USING utf8mb4),CONVERT(X'707269766174652d76616c7565' USING utf8mb4),1,CONVERT(X'323032362d30312d30312030303a30303a30302e303030303031' USING utf8mb4));
INSERT INTO `jm_transfer_orders` (`id`,`code`,`request_id`,`request_hash`,`access_hash`,`snapshot`,`contact`,`booking_status`,`payment_status`,`instructions`,`version`,`created_at`) VALUES (CONVERT(X'30303030303030302d303030302d343030302d383030302d303030303030303030303133' USING utf8mb4),CONVERT(X'505249564154452d4f524445522d3133' USING utf8mb4),CONVERT(X'707269766174652d76616c7565' USING utf8mb4),CONVERT(X'707269766174652d76616c7565' USING utf8mb4),CONVERT(X'707269766174652d76616c7565' USING utf8mb4),CONVERT(X'7b22746f74616c223a3530307d' USING utf8mb4),CONVERT(X'7b7d' USING utf8mb4),CONVERT(X'434f4e4649524d4544' USING utf8mb4),CONVERT(X'435245444954' USING utf8mb4),CONVERT(X'707269766174652d76616c7565' USING utf8mb4),1,CONVERT(X'323032362d30312d30312030303a30303a30302e303030303031' USING utf8mb4));
INSERT INTO `jm_transfer_orders` (`id`,`code`,`request_id`,`request_hash`,`access_hash`,`snapshot`,`contact`,`booking_status`,`payment_status`,`instructions`,`version`,`created_at`) VALUES (CONVERT(X'30303030303030302d303030302d343030302d383030302d303030303030303030303134' USING utf8mb4),CONVERT(X'505249564154452d4f524445522d3134' USING utf8mb4),CONVERT(X'707269766174652d76616c7565' USING utf8mb4),CONVERT(X'707269766174652d76616c7565' USING utf8mb4),CONVERT(X'707269766174652d76616c7565' USING utf8mb4),CONVERT(X'7b22746f74616c223a3230307d' USING utf8mb4),CONVERT(X'7b7d' USING utf8mb4),CONVERT(X'434f4e4649524d4544' USING utf8mb4),CONVERT(X'435245444954' USING utf8mb4),CONVERT(X'707269766174652d76616c7565' USING utf8mb4),1,CONVERT(X'323032362d30312d30312030303a30303a30302e303030303031' USING utf8mb4));
INSERT INTO `jm_transfer_orders` (`id`,`code`,`request_id`,`request_hash`,`access_hash`,`snapshot`,`contact`,`booking_status`,`payment_status`,`instructions`,`version`,`created_at`) VALUES (CONVERT(X'30303030303030302d303030302d343030302d383030302d303030303030303030303135' USING utf8mb4),CONVERT(X'505249564154452d4f524445522d3135' USING utf8mb4),CONVERT(X'707269766174652d76616c7565' USING utf8mb4),CONVERT(X'707269766174652d76616c7565' USING utf8mb4),CONVERT(X'707269766174652d76616c7565' USING utf8mb4),CONVERT(X'7b22746f74616c223a307d' USING utf8mb4),CONVERT(X'7b7d' USING utf8mb4),CONVERT(X'434f4e4649524d4544' USING utf8mb4),CONVERT(X'435245444954' USING utf8mb4),CONVERT(X'707269766174652d76616c7565' USING utf8mb4),1,CONVERT(X'323032362d30312d30312030303a30303a30302e303030303031' USING utf8mb4));
-- jm_transfer_orders: 5 rows
CREATE TABLE `jm_transfer_carts` (
  `id` LONGTEXT NULL,
  `code` LONGTEXT NULL,
  `request_id` LONGTEXT NULL,
  `request_hash` LONGTEXT NULL,
  `channel` LONGTEXT NULL,
  `partner_id` LONGTEXT NULL,
  `counter_id` LONGTEXT NULL,
  `access_hash` LONGTEXT NULL,
  `total` BIGINT NULL,
  `version` BIGINT NULL,
  `created_at` DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_carts: 0 rows
CREATE TABLE `jm_transfer_cart_orders` (
  `cart_id` LONGTEXT NULL,
  `order_id` LONGTEXT NULL,
  `position` BIGINT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_cart_orders: 0 rows
CREATE TABLE `jm_transfer_cart_actions` (
  `id` LONGTEXT NULL,
  `cart_id` LONGTEXT NULL,
  `request_hash` LONGTEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_cart_actions: 0 rows
CREATE TABLE `jm_transfer_order_archives` (
  `order_id` LONGTEXT NULL,
  `archived_by` LONGTEXT NULL,
  `reason` LONGTEXT NULL,
  `created_at` DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_order_archives: 0 rows
CREATE TABLE `jm_transfer_order_payments` (
  `id` LONGTEXT NULL,
  `order_id` LONGTEXT NULL,
  `amount` BIGINT NULL,
  `reference` LONGTEXT NULL,
  `admin_id` LONGTEXT NULL,
  `created_at` DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_order_payments: 0 rows
CREATE TABLE `jm_transfer_order_actions` (
  `id` LONGTEXT NULL,
  `order_id` LONGTEXT NULL,
  `request_hash` LONGTEXT NULL,
  `admin_id` LONGTEXT NULL,
  `action` LONGTEXT NULL,
  `note` LONGTEXT NULL,
  `created_at` DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_order_actions: 0 rows
CREATE TABLE `jm_transfer_order_tickets` (
  `id` LONGTEXT NULL,
  `order_id` LONGTEXT NULL,
  `code` LONGTEXT NULL,
  `code_hash` LONGTEXT NULL,
  `kind` LONGTEXT NULL,
  `covers` BIGINT NULL,
  `note` LONGTEXT NULL,
  `created_at` DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_order_tickets: 0 rows
CREATE TABLE `jm_transfer_ticket_files` (
  `id` LONGTEXT NULL,
  `order_id` LONGTEXT NULL,
  `ticket_id` LONGTEXT NULL,
  `name` LONGTEXT NULL,
  `mime` LONGTEXT NULL,
  `bytes` BIGINT NULL,
  `hash` LONGTEXT NULL,
  `created_at` DATETIME(6) NULL,
  `revoked_at` DATETIME(6) NULL,
  `revoke_reason` LONGTEXT NULL,
  `file_kind` LONGTEXT NULL,
  `deleted_at` DATETIME(6) NULL,
  `partner_shared` TINYINT(1) NULL,
  `sharing_version` BIGINT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_ticket_files: 0 rows
CREATE TABLE `jm_transfer_ticket_stock` (
  `id` LONGTEXT NULL,
  `lot_id` LONGTEXT NULL,
  `code` LONGTEXT NULL,
  `code_hash` LONGTEXT NULL,
  `status` LONGTEXT NULL,
  `version` BIGINT NULL,
  `reason` LONGTEXT NULL,
  `order_id` LONGTEXT NULL,
  `ticket_id` LONGTEXT NULL,
  `created_at` DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_ticket_stock: 0 rows
CREATE TABLE `jm_transfer_stock_imports` (
  `id` LONGTEXT NULL,
  `lot_id` LONGTEXT NULL,
  `request_hash` LONGTEXT NULL,
  `quantity` BIGINT NULL,
  `created_at` DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_stock_imports: 0 rows
CREATE TABLE `jm_transfer_vouchers` (
  `id` LONGTEXT NULL,
  `code` LONGTEXT NULL,
  `data` JSON NULL,
  `version` BIGINT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_vouchers: 0 rows
CREATE TABLE `jm_transfer_voucher_uses` (
  `order_id` LONGTEXT NULL,
  `voucher_id` LONGTEXT NULL,
  `released` TINYINT(1) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_voucher_uses: 0 rows
CREATE TABLE `jm_transfer_inventory_configs` (
  `product_id` LONGTEXT NULL,
  `enabled` TINYINT(1) NULL,
  `version` BIGINT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_inventory_configs: 0 rows
CREATE TABLE `jm_transfer_inventory_buckets` (
  `product_id` LONGTEXT NULL,
  `variant_id` LONGTEXT NULL,
  `visit_date` LONGTEXT NULL,
  `slot_id` LONGTEXT NULL,
  `capacity` BIGINT NULL,
  `low_stock_threshold` BIGINT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_inventory_buckets: 0 rows
CREATE TABLE `jm_transfer_inventory_allocations` (
  `order_id` LONGTEXT NULL,
  `product_id` LONGTEXT NULL,
  `variant_id` LONGTEXT NULL,
  `visit_date` LONGTEXT NULL,
  `slot_id` LONGTEXT NULL,
  `quantity` BIGINT NULL,
  `released` TINYINT(1) NULL,
  `version` BIGINT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_inventory_allocations: 0 rows
CREATE TABLE `jm_transfer_supply_lots` (
  `id` LONGTEXT NULL,
  `request_hash` LONGTEXT NULL,
  `supplier_id` LONGTEXT NULL,
  `product_id` LONGTEXT NULL,
  `variant_id` LONGTEXT NULL,
  `customer_type` LONGTEXT NULL,
  `unit_cost` BIGINT NULL,
  `total_quantity` BIGINT NULL,
  `valid_from` LONGTEXT NULL,
  `valid_to` LONGTEXT NULL,
  `priority` BIGINT NULL,
  `enabled` TINYINT(1) NULL,
  `data` JSON NULL,
  `version` BIGINT NULL,
  `created_at` DATETIME(6) NULL,
  `archived` TINYINT(1) NULL,
  `unlimited` TINYINT(1) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_supply_lots: 0 rows
CREATE TABLE `jm_transfer_supply_purchases` (
  `id` LONGTEXT NULL,
  `lot_id` LONGTEXT NULL,
  `supplier_id` LONGTEXT NULL,
  `order_id` LONGTEXT NULL,
  `customer_type` LONGTEXT NULL,
  `quantity` BIGINT NULL,
  `unit_cost` BIGINT NULL,
  `data` JSON NULL,
  `created_at` DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_supply_purchases: 0 rows
CREATE TABLE `jm_transfer_supply_allocations` (
  `order_id` LONGTEXT NULL,
  `lot_id` LONGTEXT NULL,
  `quantity` BIGINT NULL,
  `unit_cost` BIGINT NULL,
  `released` TINYINT(1) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_supply_allocations: 0 rows
CREATE TABLE `jm_transfer_supply_actions` (
  `id` LONGTEXT NULL,
  `order_id` LONGTEXT NULL,
  `request_hash` LONGTEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_supply_actions: 0 rows
CREATE TABLE `jm_transfer_supplier_payments` (
  `id` LONGTEXT NULL,
  `supplier_id` LONGTEXT NULL,
  `amount` BIGINT NULL,
  `method` LONGTEXT NULL,
  `reference` LONGTEXT NULL,
  `note` LONGTEXT NULL,
  `request_hash` LONGTEXT NULL,
  `created_at` DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_supplier_payments: 0 rows
CREATE TABLE `jm_transfer_counters` (
  `id` LONGTEXT NULL,
  `data` JSON NULL,
  `version` BIGINT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_counters: 0 rows
CREATE TABLE `jm_transfer_pos_orders` (
  `order_id` LONGTEXT NULL,
  `counter_id` LONGTEXT NULL,
  `admin_id` LONGTEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_pos_orders: 0 rows
CREATE TABLE `jm_transfer_pos_shifts` (
  `id` LONGTEXT NULL,
  `counter_id` LONGTEXT NULL,
  `opened_by` LONGTEXT NULL,
  `opened_name` LONGTEXT NULL,
  `counter_name` LONGTEXT NULL,
  `opening_cash` BIGINT NULL,
  `opening_note` LONGTEXT NULL,
  `open_hash` LONGTEXT NULL,
  `opened_at` DATETIME(6) NULL,
  `closed_at` DATETIME(6) NULL,
  `closed_by` LONGTEXT NULL,
  `close_request` LONGTEXT NULL,
  `close_hash` LONGTEXT NULL,
  `close_note` LONGTEXT NULL,
  `summary` JSON NULL,
  `version` BIGINT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_pos_shifts: 0 rows
CREATE TABLE `jm_transfer_pos_open_shifts` (
  `counter_id` LONGTEXT NULL,
  `shift_id` LONGTEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_pos_open_shifts: 0 rows
CREATE TABLE `jm_transfer_pos_shift_receipts` (
  `payment_id` LONGTEXT NULL,
  `shift_id` LONGTEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_pos_shift_receipts: 0 rows
CREATE TABLE `jm_transfer_pos_cash_movements` (
  `id` LONGTEXT NULL,
  `shift_id` LONGTEXT NULL,
  `kind` LONGTEXT NULL,
  `amount` BIGINT NULL,
  `reference` LONGTEXT NULL,
  `note` LONGTEXT NULL,
  `actor_id` LONGTEXT NULL,
  `actor_name` LONGTEXT NULL,
  `request_hash` LONGTEXT NULL,
  `created_at` DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_pos_cash_movements: 0 rows
CREATE TABLE `jm_transfer_payment_methods` (
  `payment_id` LONGTEXT NULL,
  `method` LONGTEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_payment_methods: 0 rows
CREATE TABLE `jm_transfer_price_groups` (
  `id` LONGTEXT NULL,
  `data` JSON NULL,
  `version` BIGINT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_price_groups: 0 rows
CREATE TABLE `jm_transfer_partners` (
  `id` LONGTEXT NULL,
  `email` LONGTEXT NULL,
  `data` JSON NULL,
  `version` BIGINT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `jm_transfer_partners` (`id`,`email`,`data`,`version`) VALUES (CONVERT(X'30303030303030302d303030302d343030302d383030302d303030303030303030303031' USING utf8mb4),CONVERT(X'7072697661746531406578616d706c652e74657374' USING utf8mb4),CONVERT(X'7b226964223a2230303030303030302d303030302d343030302d383030302d303030303030303030303031222c226372656469744c696d6974223a31303030307d' USING utf8mb4),1);
INSERT INTO `jm_transfer_partners` (`id`,`email`,`data`,`version`) VALUES (CONVERT(X'30303030303030302d303030302d343030302d383030302d303030303030303030303032' USING utf8mb4),CONVERT(X'7072697661746532406578616d706c652e74657374' USING utf8mb4),CONVERT(X'7b226964223a2230303030303030302d303030302d343030302d383030302d303030303030303030303032222c226372656469744c696d6974223a31303030307d' USING utf8mb4),1);
INSERT INTO `jm_transfer_partners` (`id`,`email`,`data`,`version`) VALUES (CONVERT(X'30303030303030302d303030302d343030302d383030302d303030303030303030303033' USING utf8mb4),CONVERT(X'7072697661746533406578616d706c652e74657374' USING utf8mb4),CONVERT(X'7b226964223a2230303030303030302d303030302d343030302d383030302d303030303030303030303033222c226372656469744c696d6974223a31303030307d' USING utf8mb4),1);
INSERT INTO `jm_transfer_partners` (`id`,`email`,`data`,`version`) VALUES (CONVERT(X'30303030303030302d303030302d343030302d383030302d303030303030303030303034' USING utf8mb4),CONVERT(X'7072697661746534406578616d706c652e74657374' USING utf8mb4),CONVERT(X'7b226964223a2230303030303030302d303030302d343030302d383030302d303030303030303030303034222c226372656469744c696d6974223a31303030307d' USING utf8mb4),1);
-- jm_transfer_partners: 4 rows
CREATE TABLE `jm_transfer_partner_orders` (
  `order_id` LONGTEXT NULL,
  `partner_id` LONGTEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `jm_transfer_partner_orders` (`order_id`,`partner_id`) VALUES (CONVERT(X'30303030303030302d303030302d343030302d383030302d303030303030303030303131' USING utf8mb4),CONVERT(X'30303030303030302d303030302d343030302d383030302d303030303030303030303031' USING utf8mb4));
INSERT INTO `jm_transfer_partner_orders` (`order_id`,`partner_id`) VALUES (CONVERT(X'30303030303030302d303030302d343030302d383030302d303030303030303030303132' USING utf8mb4),CONVERT(X'30303030303030302d303030302d343030302d383030302d303030303030303030303031' USING utf8mb4));
INSERT INTO `jm_transfer_partner_orders` (`order_id`,`partner_id`) VALUES (CONVERT(X'30303030303030302d303030302d343030302d383030302d303030303030303030303133' USING utf8mb4),CONVERT(X'30303030303030302d303030302d343030302d383030302d303030303030303030303032' USING utf8mb4));
INSERT INTO `jm_transfer_partner_orders` (`order_id`,`partner_id`) VALUES (CONVERT(X'30303030303030302d303030302d343030302d383030302d303030303030303030303134' USING utf8mb4),CONVERT(X'30303030303030302d303030302d343030302d383030302d303030303030303030303032' USING utf8mb4));
INSERT INTO `jm_transfer_partner_orders` (`order_id`,`partner_id`) VALUES (CONVERT(X'30303030303030302d303030302d343030302d383030302d303030303030303030303135' USING utf8mb4),CONVERT(X'30303030303030302d303030302d343030302d383030302d303030303030303030303033' USING utf8mb4));
-- jm_transfer_partner_orders: 5 rows
CREATE TABLE `jm_transfer_partner_ledger` (
  `id` LONGTEXT NULL,
  `partner_id` LONGTEXT NULL,
  `order_id` LONGTEXT NULL,
  `kind` LONGTEXT NULL,
  `amount` BIGINT NULL,
  `reference` LONGTEXT NULL,
  `note` LONGTEXT NULL,
  `actor_id` LONGTEXT NULL,
  `request_hash` LONGTEXT NULL,
  `due_at` DATETIME(6) NULL,
  `created_at` DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `jm_transfer_partner_ledger` (`id`,`partner_id`,`order_id`,`kind`,`amount`,`reference`,`note`,`actor_id`,`request_hash`,`due_at`,`created_at`) VALUES (CONVERT(X'30303030303030302d303030302d343030302d383030302d303030303030303030313131' USING utf8mb4),CONVERT(X'30303030303030302d303030302d343030302d383030302d303030303030303030303031' USING utf8mb4),CONVERT(X'30303030303030302d303030302d343030302d383030302d303030303030303030303131' USING utf8mb4),CONVERT(X'434841524745' USING utf8mb4),1000,CONVERT(X'505249564154452d4f524445522d3131' USING utf8mb4),CONVERT(X'707269766174652d76616c7565' USING utf8mb4),CONVERT(X'707269766174652d76616c7565' USING utf8mb4),CONVERT(X'707269766174652d76616c7565' USING utf8mb4),CONVERT(X'323032362d30312d30322030303a30303a30302e303030303030' USING utf8mb4),CONVERT(X'323032362d30312d30312030303a30303a30302e303030303031' USING utf8mb4));
INSERT INTO `jm_transfer_partner_ledger` (`id`,`partner_id`,`order_id`,`kind`,`amount`,`reference`,`note`,`actor_id`,`request_hash`,`due_at`,`created_at`) VALUES (CONVERT(X'30303030303030302d303030302d343030302d383030302d303030303030303030313132' USING utf8mb4),CONVERT(X'30303030303030302d303030302d343030302d383030302d303030303030303030303031' USING utf8mb4),CONVERT(X'30303030303030302d303030302d343030302d383030302d303030303030303030303132' USING utf8mb4),CONVERT(X'434841524745' USING utf8mb4),2000,CONVERT(X'505249564154452d4f524445522d3132' USING utf8mb4),CONVERT(X'707269766174652d76616c7565' USING utf8mb4),CONVERT(X'707269766174652d76616c7565' USING utf8mb4),CONVERT(X'707269766174652d76616c7565' USING utf8mb4),CONVERT(X'323039392d30312d30312030303a30303a30302e303030303030' USING utf8mb4),CONVERT(X'323032362d30312d30312030303a30303a30302e303030303031' USING utf8mb4));
INSERT INTO `jm_transfer_partner_ledger` (`id`,`partner_id`,`order_id`,`kind`,`amount`,`reference`,`note`,`actor_id`,`request_hash`,`due_at`,`created_at`) VALUES (CONVERT(X'30303030303030302d303030302d343030302d383030302d303030303030303030313133' USING utf8mb4),CONVERT(X'30303030303030302d303030302d343030302d383030302d303030303030303030303032' USING utf8mb4),CONVERT(X'30303030303030302d303030302d343030302d383030302d303030303030303030303133' USING utf8mb4),CONVERT(X'434841524745' USING utf8mb4),500,CONVERT(X'505249564154452d4f524445522d3133' USING utf8mb4),CONVERT(X'707269766174652d76616c7565' USING utf8mb4),CONVERT(X'707269766174652d76616c7565' USING utf8mb4),CONVERT(X'707269766174652d76616c7565' USING utf8mb4),CONVERT(X'323039392d30312d30312030303a30303a30302e303030303030' USING utf8mb4),CONVERT(X'323032362d30312d30312030303a30303a30302e303030303031' USING utf8mb4));
INSERT INTO `jm_transfer_partner_ledger` (`id`,`partner_id`,`order_id`,`kind`,`amount`,`reference`,`note`,`actor_id`,`request_hash`,`due_at`,`created_at`) VALUES (CONVERT(X'30303030303030302d303030302d343030302d383030302d303030303030303030313134' USING utf8mb4),CONVERT(X'30303030303030302d303030302d343030302d383030302d303030303030303030303032' USING utf8mb4),CONVERT(X'30303030303030302d303030302d343030302d383030302d303030303030303030303134' USING utf8mb4),CONVERT(X'434841524745' USING utf8mb4),200,CONVERT(X'505249564154452d4f524445522d3134' USING utf8mb4),CONVERT(X'707269766174652d76616c7565' USING utf8mb4),CONVERT(X'707269766174652d76616c7565' USING utf8mb4),CONVERT(X'707269766174652d76616c7565' USING utf8mb4),CONVERT(X'323032362d30312d30322030303a30303a30302e303030303030' USING utf8mb4),CONVERT(X'323032362d30312d30312030303a30303a30302e303030303031' USING utf8mb4));
INSERT INTO `jm_transfer_partner_ledger` (`id`,`partner_id`,`order_id`,`kind`,`amount`,`reference`,`note`,`actor_id`,`request_hash`,`due_at`,`created_at`) VALUES (CONVERT(X'30303030303030302d303030302d343030302d383030302d303030303030303030313135' USING utf8mb4),CONVERT(X'30303030303030302d303030302d343030302d383030302d303030303030303030303033' USING utf8mb4),CONVERT(X'30303030303030302d303030302d343030302d383030302d303030303030303030303135' USING utf8mb4),CONVERT(X'434841524745' USING utf8mb4),0,CONVERT(X'505249564154452d4f524445522d3135' USING utf8mb4),CONVERT(X'707269766174652d76616c7565' USING utf8mb4),CONVERT(X'707269766174652d76616c7565' USING utf8mb4),CONVERT(X'707269766174652d76616c7565' USING utf8mb4),CONVERT(X'323032362d30312d30322030303a30303a30302e303030303030' USING utf8mb4),CONVERT(X'323032362d30312d30312030303a30303a30302e303030303031' USING utf8mb4));
INSERT INTO `jm_transfer_partner_ledger` (`id`,`partner_id`,`order_id`,`kind`,`amount`,`reference`,`note`,`actor_id`,`request_hash`,`due_at`,`created_at`) VALUES (CONVERT(X'30303030303030302d303030302d343030302d383030302d303030303030303030323031' USING utf8mb4),CONVERT(X'30303030303030302d303030302d343030302d383030302d303030303030303030303031' USING utf8mb4),NULL,CONVERT(X'52455041594d454e54' USING utf8mb4),-1500,CONVERT(X'505249564154452d52455041594d454e542d323031' USING utf8mb4),CONVERT(X'707269766174652d76616c7565' USING utf8mb4),CONVERT(X'707269766174652d76616c7565' USING utf8mb4),CONVERT(X'707269766174652d76616c7565' USING utf8mb4),NULL,CONVERT(X'323032362d30322d30312030303a30303a30302e303030303030' USING utf8mb4));
INSERT INTO `jm_transfer_partner_ledger` (`id`,`partner_id`,`order_id`,`kind`,`amount`,`reference`,`note`,`actor_id`,`request_hash`,`due_at`,`created_at`) VALUES (CONVERT(X'30303030303030302d303030302d343030302d383030302d303030303030303030323032' USING utf8mb4),CONVERT(X'30303030303030302d303030302d343030302d383030302d303030303030303030303032' USING utf8mb4),NULL,CONVERT(X'52455041594d454e54' USING utf8mb4),-100,CONVERT(X'505249564154452d52455041594d454e542d323032' USING utf8mb4),CONVERT(X'707269766174652d76616c7565' USING utf8mb4),CONVERT(X'707269766174652d76616c7565' USING utf8mb4),CONVERT(X'707269766174652d76616c7565' USING utf8mb4),NULL,CONVERT(X'323032362d30322d30312030303a30303a30302e303030303030' USING utf8mb4));
-- jm_transfer_partner_ledger: 7 rows
CREATE TABLE `jm_transfer_payment_references` (
  `reference` LONGTEXT NULL,
  `kind` LONGTEXT NULL,
  `entity_id` LONGTEXT NULL,
  `amount` BIGINT NULL,
  `created_at` DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `jm_transfer_payment_references` (`reference`,`kind`,`entity_id`,`amount`,`created_at`) VALUES (CONVERT(X'505249564154452d52455041594d454e542d323031' USING utf8mb4),CONVERT(X'504152544e45525f52455041594d454e54' USING utf8mb4),CONVERT(X'30303030303030302d303030302d343030302d383030302d303030303030303030303031' USING utf8mb4),1500,CONVERT(X'323032362d30322d30312030303a30303a30302e303030303030' USING utf8mb4));
INSERT INTO `jm_transfer_payment_references` (`reference`,`kind`,`entity_id`,`amount`,`created_at`) VALUES (CONVERT(X'505249564154452d52455041594d454e542d323032' USING utf8mb4),CONVERT(X'504152544e45525f52455041594d454e54' USING utf8mb4),CONVERT(X'30303030303030302d303030302d343030302d383030302d303030303030303030303032' USING utf8mb4),100,CONVERT(X'323032362d30322d30312030303a30303a30302e303030303030' USING utf8mb4));
-- jm_transfer_payment_references: 2 rows
CREATE TABLE `jm_transfer_gateway_events` (
  `id` LONGTEXT NULL,
  `attempt_id` LONGTEXT NULL,
  `order_id` LONGTEXT NULL,
  `provider` LONGTEXT NULL,
  `mode` LONGTEXT NULL,
  `amount` BIGINT NULL,
  `status` LONGTEXT NULL,
  `reason` LONGTEXT NULL,
  `fingerprint` LONGTEXT NULL,
  `created_at` DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_gateway_events: 0 rows
CREATE TABLE `jm_transfer_gateway_resolutions` (
  `event_id` LONGTEXT NULL,
  `target_code` LONGTEXT NULL,
  `admin_id` LONGTEXT NULL,
  `note` LONGTEXT NULL,
  `original_reason` LONGTEXT NULL,
  `original_order_id` LONGTEXT NULL,
  `created_at` DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_gateway_resolutions: 0 rows
CREATE TABLE `jm_transfer_transport_jobs` (
  `order_id` LONGTEXT NULL,
  `visit_date` LONGTEXT NULL,
  `status` LONGTEXT NULL,
  `data` JSON NULL,
  `version` BIGINT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_transport_jobs: 0 rows
CREATE TABLE `jm_transfer_ride_bookings` (
  `id` LONGTEXT NULL,
  `code` LONGTEXT NULL,
  `request_key` LONGTEXT NULL,
  `visit_date` LONGTEXT NULL,
  `status` LONGTEXT NULL,
  `trip_id` LONGTEXT NULL,
  `data` JSON NULL,
  `version` BIGINT NULL,
  `created_at` DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_ride_bookings: 0 rows
CREATE TABLE `jm_transfer_ride_pools` (
  `id` LONGTEXT NULL,
  `visit_date` LONGTEXT NULL,
  `from_id` LONGTEXT NULL,
  `to_id` LONGTEXT NULL,
  `vehicle_id` LONGTEXT NULL,
  `departure` LONGTEXT NULL,
  `target` BIGINT NULL,
  `total` BIGINT NULL,
  `status` LONGTEXT NULL,
  `data` JSON NULL,
  `version` BIGINT NULL,
  `created_at` DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_ride_pools: 0 rows
CREATE TABLE `jm_transfer_ride_payment_events` (
  `event_id` LONGTEXT NULL,
  `ride_id` LONGTEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_ride_payment_events: 0 rows
CREATE TABLE `jm_transfer_gift_orders` (
  `id` CHAR(36) NULL,
  `code` LONGTEXT NULL,
  `status` LONGTEXT NULL,
  `data` JSON NULL,
  `created_at` DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_gift_orders: 0 rows
CREATE TABLE `jm_transfer_checkout_bundles` (
  `id` CHAR(36) NULL,
  `code` LONGTEXT NULL,
  `access_hash` LONGTEXT NULL,
  `total` BIGINT NULL,
  `status` LONGTEXT NULL,
  `data` JSON NULL,
  `created_at` DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_checkout_bundles: 0 rows
CREATE TABLE `jm_transfer_checkout_members` (
  `kind` LONGTEXT NULL,
  `entity_id` LONGTEXT NULL,
  `bundle_id` CHAR(36) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_checkout_members: 0 rows
CREATE TABLE `jm_transfer_refunds` (
  `id` CHAR(36) NULL,
  `root_kind` LONGTEXT NULL,
  `root_id` LONGTEXT NULL,
  `root_code` LONGTEXT NULL,
  `amount` BIGINT NULL,
  `refunded_on` LONGTEXT NULL,
  `reference_key` LONGTEXT NULL,
  `data` JSON NULL,
  `created_at` DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_refunds: 0 rows
CREATE TABLE `jm_transfer_stays` (
  `id` CHAR(36) NULL,
  `slug` LONGTEXT NULL,
  `data` JSON NULL,
  `version` BIGINT NULL,
  `updated_at` DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_stays: 0 rows
CREATE TABLE `jm_transfer_stay_aliases` (
  `lang` LONGTEXT NULL,
  `slug` LONGTEXT NULL,
  `hotel_id` CHAR(36) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_stay_aliases: 0 rows
CREATE TABLE `jm_transfer_stay_bookings` (
  `id` CHAR(36) NULL,
  `code` LONGTEXT NULL,
  `hotel_id` CHAR(36) NULL,
  `room_id` CHAR(36) NULL,
  `check_in` LONGTEXT NULL,
  `check_out` LONGTEXT NULL,
  `rooms` BIGINT NULL,
  `status` LONGTEXT NULL,
  `access_hash` LONGTEXT NULL,
  `request_hash` LONGTEXT NULL,
  `data` JSON NULL,
  `expires_at` DATETIME(6) NULL,
  `version` BIGINT NULL,
  `created_at` DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_stay_bookings: 0 rows
CREATE TABLE `jm_transfer_stay_vouchers` (
  `id` CHAR(36) NULL,
  `code` LONGTEXT NULL,
  `data` JSON NULL,
  `version` BIGINT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_stay_vouchers: 0 rows
CREATE TABLE `jm_transfer_stay_payment_events` (
  `event_id` LONGTEXT NULL,
  `booking_id` CHAR(36) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_stay_payment_events: 0 rows
CREATE TABLE `jm_transfer_contact_requests` (
  `id` LONGTEXT NULL,
  `data` JSON NULL,
  `status` LONGTEXT NULL,
  `created_at` DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- jm_transfer_contact_requests: 0 rows
-- TRANSFER COMPLETE {"jinmemo_product_types":0,"jinmemo_destinations":0,"jinmemo_suppliers":0,"jinmemo_media":0,"jinmemo_products":0,"jinmemo_pages":0,"jinmemo_post_categories":0,"jinmemo_posts":0,"jinmemo_post_revisions":0,"jinmemo_post_metrics":0,"jinmemo_slug_aliases":0,"jinmemo_policy_pages":0,"jinmemo_orders":5,"jinmemo_carts":0,"jinmemo_cart_orders":0,"jinmemo_cart_actions":0,"jinmemo_order_archives":0,"jinmemo_order_payments":0,"jinmemo_order_actions":0,"jinmemo_order_tickets":0,"jinmemo_ticket_files":0,"jinmemo_ticket_stock":0,"jinmemo_stock_imports":0,"jinmemo_vouchers":0,"jinmemo_voucher_uses":0,"jinmemo_inventory_configs":0,"jinmemo_inventory_buckets":0,"jinmemo_inventory_allocations":0,"jinmemo_supply_lots":0,"jinmemo_supply_purchases":0,"jinmemo_supply_allocations":0,"jinmemo_supply_actions":0,"jinmemo_supplier_payments":0,"jinmemo_counters":0,"jinmemo_pos_orders":0,"jinmemo_pos_shifts":0,"jinmemo_pos_open_shifts":0,"jinmemo_pos_shift_receipts":0,"jinmemo_pos_cash_movements":0,"jinmemo_payment_methods":0,"jinmemo_price_groups":0,"jinmemo_partners":4,"jinmemo_partner_orders":5,"jinmemo_partner_ledger":7,"jinmemo_payment_references":2,"jinmemo_gateway_events":0,"jinmemo_gateway_resolutions":0,"jinmemo_transport_jobs":0,"jinmemo_ride_bookings":0,"jinmemo_ride_pools":0,"jinmemo_ride_payment_events":0,"jinmemo_gift_orders":0,"jinmemo_checkout_bundles":0,"jinmemo_checkout_members":0,"jinmemo_refunds":0,"jinmemo_stays":0,"jinmemo_stay_aliases":0,"jinmemo_stay_bookings":0,"jinmemo_stay_vouchers":0,"jinmemo_stay_payment_events":0,"jinmemo_contact_requests":0}
