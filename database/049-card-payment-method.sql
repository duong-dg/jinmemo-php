-- Preserve the original method field and field order while allowing SEPAY_CARD.
ALTER TABLE jingo_order_payments MODIFY COLUMN method VARCHAR(20) NOT NULL;
