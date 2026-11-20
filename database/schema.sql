CREATE DATABASE pharmacy CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE pharmacy;

CREATE TABLE stock_balances (
    balance_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    drug_name VARCHAR(200) NOT NULL,
    batch_number VARCHAR(100) NOT NULL,
    expiry_date DATE NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    storage_temperature VARCHAR(100) NOT NULL,
    PRIMARY KEY (balance_id)
) ENGINE=InnoDB;

-- Fictional records for the laboratory demonstration.
INSERT INTO stock_balances
    (drug_name, batch_number, expiry_date, quantity, storage_temperature)
VALUES
    ('Учебный препарат А', '00021-A', '2027-12-31', 120, '+2…+8 °C'),
    ('Учебный препарат Б', '00022-B', '2028-06-30', 45, '+15…+25 °C'),
    ('Тестовый раствор В', '00023-C', '2027-09-15', 0, '+2…+8 °C');

CREATE USER 'pharmacy_reader'@'127.0.0.1' IDENTIFIED BY 'Lab21-Med-2026!';
GRANT SELECT ON pharmacy.stock_balances TO 'pharmacy_reader'@'127.0.0.1';
