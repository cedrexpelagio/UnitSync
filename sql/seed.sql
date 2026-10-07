-- UnitSync Seed Data for MVP (Stage 0)

-- Programs
INSERT INTO `programs` (`code`, `name`) VALUES
('BSIT', 'Bachelor of Science in Information Technology'),
('BSCE', 'Bachelor of Science in Civil Engineering'),
('BSCS', 'Bachelor of Science in Computer Science'),
('BSCrim', 'Bachelor of Science in Criminology'),
('BSEd', 'Bachelor of Secondary Education')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Companies
INSERT INTO `companies` (`id`, `name`) VALUES
(1, 'Alpha'),
(2, 'Bravo')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Platoons for Alpha and Bravo
INSERT INTO `platoons` (`id`, `company_id`, `name`) VALUES
(1, 1, '1st Platoon'),
(2, 1, '2nd Platoon'),
(3, 2, '1st Platoon'),
(4, 2, '2nd Platoon')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);
