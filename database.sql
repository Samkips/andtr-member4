CREATE DATABASE IF NOT EXISTS andtr_member4 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE andtr_member4;
CREATE TABLE IF NOT EXISTS users (
 id INT PRIMARY KEY, name VARCHAR(100) NOT NULL, email VARCHAR(150) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL, role ENUM('employee','customer') NOT NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS notices (
 id INT PRIMARY KEY, reference VARCHAR(40) NOT NULL, device VARCHAR(100) NOT NULL, details TEXT NOT NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS findings (
 id INT PRIMARY KEY, notice_id INT NOT NULL, title VARCHAR(150) NOT NULL,
 FOREIGN KEY (notice_id) REFERENCES notices(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS shared_findings (
 id INT AUTO_INCREMENT PRIMARY KEY, finding_id INT NOT NULL, employee_id INT NOT NULL,
 customer_id INT NOT NULL, message VARCHAR(500) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX (customer_id, created_at), INDEX (employee_id, created_at),
 FOREIGN KEY (finding_id) REFERENCES findings(id),
 FOREIGN KEY (employee_id) REFERENCES users(id), FOREIGN KEY (customer_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS customer_comments (
 id INT AUTO_INCREMENT PRIMARY KEY, share_id INT NOT NULL, author_id INT NOT NULL,
 body VARCHAR(2000) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX (share_id, id),
 FOREIGN KEY (share_id) REFERENCES shared_findings(id), FOREIGN KEY (author_id) REFERENCES users(id)
) ENGINE=InnoDB;
INSERT IGNORE INTO notices VALUES
 (1,'ANDTR-001','HeartGuide Monitor','Review the recorded overheating incident and contact the equipment team.'),
 (2,'ANDTR-002','Infusion Pump','Confirm the software version with the equipment team.');
INSERT IGNORE INTO findings VALUES (1,1,'Battery overheating risk'),(2,2,'Software update required');
INSERT IGNORE INTO users VALUES (1,'John Smith','employee@example.test','$2y$10$l61G8xACt1IONMvyET3wR.eSTiYybFrd9SDBN3/YsGAe6Czri9cb.','employee');
INSERT IGNORE INTO users VALUES (2,'City Hospital NHS Trust','customer@example.test','$2y$10$Qn92yIcVmWOmPzbG1SA57uzsC6ZTFsafjnJrYmhd3zmY.w747bz5K','customer');
INSERT IGNORE INTO users VALUES (3,'Riverside Medical Centre','customer2@example.test','$2y$10$xRx/iWAY.5NTFLnmsphZn.liiENkZnmuy5PNVqK/bThKPuCOVEdya','customer');
