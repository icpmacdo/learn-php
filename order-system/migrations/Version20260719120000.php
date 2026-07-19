<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stage 3 tables: the Notification log and the two CQRS-lite read models.
 * None of these are ORM-mapped (the schema_filter in doctrine.yaml hides
 * them from diff) — they are written by DBAL adapters behind
 * Application-layer ports.
 *
 *  - notification_log is an append-only record of "emails" the LoggingMailer
 *    would have sent — no aggregate, no lifecycle, deliberately thin.
 *  - read_product_list composes TWO contexts (Catalog merchandising +
 *    Inventory availability) without a cross-context join at request time:
 *    each side's projector maintains its own columns. product_known marks
 *    rows the Catalog has actually announced — the warehouse may hold stock
 *    for a SKU the catalog has never heard of, and such availability-only
 *    rows must not surface in the product listing (but must survive until
 *    the product IS added, whatever order the events arrive in).
 *  - read_order_summary is the flat order-history row: status/total listing
 *    without hydrating full Order aggregates.
 *
 * Both read models are BACKFILLED from the write model below: a projection
 * rebuilt from current state — the one-off "replay" a synchronous projector
 * never needs again, but an existing dev database does once.
 */
final class Version20260719120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notification log + CQRS-lite read models (read_product_list, read_order_summary) with backfill';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE notification_log (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(32) NOT NULL, customer_id VARCHAR(64) NOT NULL, order_id VARCHAR(36) NOT NULL, subject VARCHAR(255) NOT NULL, body TEXT NOT NULL, created_at DATETIME NOT NULL, INDEX idx_notification_log_order (order_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('CREATE TABLE read_product_list (sku VARCHAR(32) NOT NULL, name VARCHAR(255) NOT NULL DEFAULT \'\', price_minor INT NOT NULL DEFAULT 0, currency VARCHAR(3) NOT NULL DEFAULT \'\', active TINYINT(1) NOT NULL DEFAULT 0, available INT NOT NULL DEFAULT 0, product_known TINYINT(1) NOT NULL DEFAULT 0, PRIMARY KEY (sku)) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('CREATE TABLE read_order_summary (order_id VARCHAR(36) NOT NULL, customer_id VARCHAR(64) NOT NULL, status VARCHAR(10) NOT NULL, total_minor INT NOT NULL, currency VARCHAR(3) NOT NULL, line_count INT NOT NULL, placed_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX idx_read_order_summary_customer (customer_id, placed_at), PRIMARY KEY (order_id)) DEFAULT CHARACTER SET utf8mb4');

        // --- backfill: project current write-model state once ---------------
        $this->addSql(<<<'SQL'
            INSERT INTO read_product_list (sku, name, price_minor, currency, active, available, product_known)
            SELECT p.sku, p.name, p.price_minor, p.price_currency, p.active,
                   GREATEST(COALESCE(s.on_hand, 0) - COALESCE(s.reserved, 0), 0), 1
            FROM catalog_product p
            LEFT JOIN inventory_stock_item s ON s.sku = p.sku
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO read_product_list (sku, name, price_minor, currency, active, available, product_known)
            SELECT s.sku, '', 0, '', 0, GREATEST(s.on_hand - s.reserved, 0), 0
            FROM inventory_stock_item s
            LEFT JOIN catalog_product p ON p.sku = s.sku
            WHERE p.sku IS NULL
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO read_order_summary (order_id, customer_id, status, total_minor, currency, line_count, placed_at, updated_at)
            SELECT o.id, o.customer_id, o.status,
                   COALESCE(SUM(l.unit_price_minor * l.quantity), 0), o.currency, COUNT(l.id),
                   o.placed_at, COALESCE(o.cancelled_at, o.shipped_at, o.paid_at, o.placed_at)
            FROM ordering_order o
            LEFT JOIN ordering_order_line l ON l.order_id = o.id
            GROUP BY o.id, o.customer_id, o.status, o.currency, o.placed_at, o.cancelled_at, o.shipped_at, o.paid_at
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE read_order_summary');
        $this->addSql('DROP TABLE read_product_list');
        $this->addSql('DROP TABLE notification_log');
    }
}
