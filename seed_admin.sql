-- Seed default admin user (password: admin123)
-- Usage: mysql -u root < seed_admin.sql
USE scrap_management_system;

INSERT INTO branches (id, name, phone, status, created_at) VALUES
(1, 'Head Office', '0300-1234567', 1, CURDATE());

INSERT INTO users (username, password, full_name, role, branch_id, status, created_at) VALUES
('admin', 'admin123', 'Administrator', 'admin', 1, 1, CURDATE());

INSERT INTO bank_accounts (account_name, bank_name, account_no, account_type, opening_balance, current_balance, status, created_at) VALUES
('Default Account', 'Default Bank', 'BNK-DEFAULT', 'current', 0.00, 0.00, 1, CURDATE());

INSERT INTO expense_categories (name, description, status, created_at) VALUES
('Rent', 'Godown/office rent', 1, CURDATE()),
('Electricity', 'Utility bills', 1, CURDATE()),
('Transport', 'Loading/transport charges', 1, CURDATE()),
('Labour', 'Labour/loading charges', 1, CURDATE()),
('Staff Salary', 'Employee salaries', 1, CURDATE()),
('Misc', 'Other expenses', 1, CURDATE());

INSERT INTO categories (name, description, status, created_at) VALUES
('Iron & Steel', 'Iron / steel scrap', 1, CURDATE()),
('Copper', 'Copper scrap (wire, bars)', 1, CURDATE()),
('Brass', 'Brass scrap', 1, CURDATE()),
('Aluminum', 'Aluminum scrap', 1, CURDATE()),
('Plastic', 'Plastic scrap', 1, CURDATE()),
('Paper & Carton', 'Paper / cardboard waste', 1, CURDATE()),
('Glass', 'Glass scrap', 1, CURDATE()),
('Electrical', 'Motors, wires, e-scrap', 1, CURDATE());