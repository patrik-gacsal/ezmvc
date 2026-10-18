-- Minimal example schema for ezmvc.
--
-- Exercises every ORM/scaffolder feature in one small pair of tables:
-- auto-increment id, a nullable int, a tinyint(1) boolean, a nullable string,
-- a read-only created timestamp, a soft-delete timestamp, and a foreign key.
--
-- Usage:
--   1. Create a database and point Config.php at it.
--   2. Import this file: mysql -u USER -p DB_NAME < example.sql
--   3. ezmvc scaffold
--
-- This generates TestEntity/TestEntityDto and TestEntity2/TestEntity2Dto with
-- #[Table]/#[Column]/#[Id]/#[ReadOnlyField] attributes, a deleteLogical()
-- method on both (delete_date), and toDto()/fromDto() — ready to exercise
-- immediately without needing a real project schema described first.

CREATE TABLE IF NOT EXISTS `test_entity` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `numeric_value` int(11) DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `note` varchar(255) DEFAULT NULL,
  `create_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `delete_date` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `test_entity2` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `test_entity_id` int(11) DEFAULT NULL,
  `delete_date` datetime DEFAULT NULL,
  `create_date` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
