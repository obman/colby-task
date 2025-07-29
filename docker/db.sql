CREATE DATABASE IF NOT EXISTS colby;

USE colby;

CREATE TABLE IF NOT EXISTS products(
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL
);

CREATE TABLE IF NOT EXISTS media (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    node_id VARCHAR(255) NOT NULL UNIQUE,
    type VARCHAR(255) NOT NULL,
    src TEXT,
    position INT NOT NULL,
    CONSTRAINT fk_product
        FOREIGN KEY (product_id)
            REFERENCES products(id)
            ON DELETE CASCADE
);

INSERT INTO products (title) VALUE ("Resident Evil Village Pre-order (PC)");