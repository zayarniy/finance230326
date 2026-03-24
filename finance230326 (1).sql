-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Хост: 127.0.0.1
-- Время создания: Мар 24 2026 г., 12:13
-- Версия сервера: 10.4.32-MariaDB
-- Версия PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- База данных: `finance230326`
--

-- --------------------------------------------------------

--
-- Структура таблицы `accounts`
--

CREATE TABLE `accounts` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `account_number` varchar(100) NOT NULL,
  `bank_name` varchar(100) NOT NULL,
  `note` text DEFAULT NULL,
  `opening_date` date DEFAULT NULL,
  `color` varchar(7) DEFAULT '#6c757d',
  `icon` varchar(50) DEFAULT 'bi-bank',
  `initial_balance` decimal(15,2) DEFAULT 0.00,
  `current_balance` decimal(15,2) DEFAULT 0.00,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `accounts`
--

INSERT INTO `accounts` (`id`, `user_id`, `account_number`, `bank_name`, `note`, `opening_date`, `color`, `icon`, `initial_balance`, `current_balance`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 1, '40817810000000000001', 'Сбербанк', NULL, NULL, '#28a745', 'bi-bank', 50000.00, 50000.00, 1, '2026-03-23 19:11:55', '2026-03-23 19:11:55'),
(2, 1, '40817810000000000002', 'Тинькофф', NULL, NULL, '#ffc107', 'bi-credit-card', 30000.00, 30000.00, 1, '2026-03-23 19:11:55', '2026-03-23 19:11:55'),
(3, 1, 'Наличные', 'Домашний кошелек', NULL, NULL, '#17a2b8', 'bi-cash', 10000.00, 10000.00, 1, '2026-03-23 19:11:55', '2026-03-23 19:11:55'),
(7, 3, '3806', 'Совкомбанк', '', '2026-03-23', '#d2220f', 'bi-credit-card', 5618.00, 5168.00, 1, '2026-03-23 21:31:36', '2026-03-23 23:44:11'),
(8, 3, '9918', 'ВТБ', 'Основной счет для зарплаты', '2026-03-23', '#1620ac', 'bi-credit-card', 9100.00, 9100.00, 1, '2026-03-23 21:32:17', '2026-03-23 21:35:37'),
(12, 3, '9813', 'Сбербанк', '', '2026-03-23', '#29a211', 'bi-credit-card', 4638.00, 4638.00, 1, '2026-03-23 21:55:04', '2026-03-23 21:55:04'),
(13, 3, '1895', 'Яндекс', '', '2026-03-23', '#f0e80f', 'bi-credit-card', 4458.00, 12.00, 1, '2026-03-23 22:20:19', '2026-03-23 22:22:19');

-- --------------------------------------------------------

--
-- Структура таблицы `audit_log`
--

CREATE TABLE `audit_log` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,
  `entity_type` varchar(50) DEFAULT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `categories`
--

CREATE TABLE `categories` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `color` varchar(7) DEFAULT '#6c757d',
  `type` enum('income','expense') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `categories`
--

INSERT INTO `categories` (`id`, `user_id`, `name`, `color`, `type`, `created_at`, `updated_at`) VALUES
(1, 1, 'Зарплата', '#28a745', 'income', '2026-03-23 19:11:55', '2026-03-23 19:11:55'),
(2, 1, 'Фриланс', '#17a2b8', 'income', '2026-03-23 19:11:55', '2026-03-23 19:11:55'),
(3, 1, 'Продукты', '#dc3545', 'expense', '2026-03-23 19:11:55', '2026-03-23 19:11:55'),
(4, 1, 'Транспорт', '#ffc107', 'expense', '2026-03-23 19:11:55', '2026-03-23 19:11:55'),
(5, 1, 'Развлечения', '#6f42c1', 'expense', '2026-03-23 19:11:55', '2026-03-23 19:11:55'),
(6, 3, 'Зарплата', '#28a745', 'income', '2026-03-23 20:01:27', '2026-03-23 20:01:27'),
(8, 3, 'Подарки', '#ffc107', 'income', '2026-03-23 20:01:28', '2026-03-23 20:01:28'),
(9, 3, 'Продукты', '#dc3545', 'expense', '2026-03-23 20:01:28', '2026-03-23 20:01:28'),
(10, 3, 'Транспорт', '#ffc107', 'expense', '2026-03-23 20:01:28', '2026-03-23 20:01:28'),
(11, 3, 'Коммунальные услуги', '#6c757d', 'expense', '2026-03-23 20:01:28', '2026-03-23 20:01:28'),
(12, 3, 'Развлечения', '#6f42c1', 'expense', '2026-03-23 20:01:28', '2026-03-23 20:01:28'),
(13, 3, 'Здоровье', '#20c997', 'expense', '2026-03-23 20:01:29', '2026-03-23 20:01:29'),
(14, 3, 'Образование', '#fd7e14', 'expense', '2026-03-23 20:01:29', '2026-03-23 20:01:29'),
(15, 3, 'Одежда', '#e83e8c', 'expense', '2026-03-23 20:01:29', '2026-03-23 20:01:29'),
(16, 3, 'Корректировка баланса', '#dc3545', 'expense', '2026-03-23 21:27:24', '2026-03-23 21:27:24'),
(17, 3, 'Корректировка баланса', '#28a745', 'income', '2026-03-23 21:43:29', '2026-03-23 21:43:29'),
(18, 3, 'Связь', '#6c757d', 'expense', '2026-03-23 22:10:03', '2026-03-23 22:10:03'),
(19, 3, 'Оплата кредита', '#d41633', 'expense', '2026-03-23 22:21:53', '2026-03-23 22:21:53');

-- --------------------------------------------------------

--
-- Структура таблицы `tags`
--

CREATE TABLE `tags` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `color` varchar(7) DEFAULT '#6c757d',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `tags`
--

INSERT INTO `tags` (`id`, `user_id`, `name`, `color`, `created_at`, `updated_at`) VALUES
(1, 1, 'важное', '#dc3545', '2026-03-23 19:11:55', '2026-03-23 19:11:55'),
(2, 1, 'ежемесячное', '#28a745', '2026-03-23 19:11:55', '2026-03-23 19:11:55'),
(3, 1, 'разовое', '#6c757d', '2026-03-23 19:11:55', '2026-03-23 19:11:55'),
(4, 1, 'семья', '#fd7e14', '2026-03-23 19:11:55', '2026-03-23 19:11:55'),
(5, 3, 'важное', '#dc3545', '2026-03-23 20:01:29', '2026-03-23 20:01:29'),
(6, 3, 'ежемесячное', '#28a745', '2026-03-23 20:01:29', '2026-03-23 20:01:29'),
(7, 3, 'разовое', '#6c757d', '2026-03-23 20:01:29', '2026-03-23 20:01:29'),
(8, 3, 'семья', '#fd7e14', '2026-03-23 20:01:29', '2026-03-23 20:01:29'),
(9, 3, 'работа', '#007bff', '2026-03-23 20:01:29', '2026-03-23 20:01:29'),
(10, 3, 'хобби', '#e83e8c', '2026-03-23 20:01:29', '2026-03-23 20:01:29'),
(12, 3, 'связь', '#6c757d', '2026-03-23 21:39:41', '2026-03-23 21:39:41'),
(13, 3, 'Интернет', '#6c757d', '2026-03-23 21:39:48', '2026-03-23 21:39:48'),
(14, 3, 'жена', '#ecf000', '2026-03-24 11:02:22', '2026-03-24 11:02:22');

-- --------------------------------------------------------

--
-- Структура таблицы `transactions`
--

CREATE TABLE `transactions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `type` enum('income','expense') NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `transaction_date` date NOT NULL,
  `description` text DEFAULT NULL,
  `tags_text` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `transactions`
--

INSERT INTO `transactions` (`id`, `user_id`, `account_id`, `category_id`, `type`, `amount`, `transaction_date`, `description`, `tags_text`, `created_at`, `updated_at`) VALUES
(14, 3, 7, 18, 'expense', 450.00, '2026-03-23', '', '', '2026-03-23 22:10:23', '2026-03-23 23:44:11'),
(15, 3, 13, 19, 'expense', 4446.00, '2026-03-26', '', 'хобби', '2026-03-23 22:22:19', '2026-03-23 22:22:19');

-- --------------------------------------------------------

--
-- Структура таблицы `transfers`
--

CREATE TABLE `transfers` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `from_account_id` int(11) NOT NULL,
  `to_account_id` int(11) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `transfer_date` datetime NOT NULL,
  `description` text DEFAULT NULL,
  `template_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `transfer_templates`
--

CREATE TABLE `transfer_templates` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `from_account_id` int(11) NOT NULL,
  `to_account_id` int(11) NOT NULL,
  `template_name` varchar(100) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `password_hash`, `created_at`, `updated_at`) VALUES
(1, 'testuser', 'test@example.com', '$2y$10$YourHashHere', '2026-03-23 19:11:54', '2026-03-23 19:11:54'),
(3, 'admin', 'zaazaa@yandex.ru', '$2y$10$TCf2rcf1NRMjUHxDAD/QEuczvMTR2cfztuCbLriF7b7G3hjSduB8G', '2026-03-23 20:01:27', '2026-03-23 22:52:26');

-- --------------------------------------------------------

--
-- Структура таблицы `user_settings`
--

CREATE TABLE `user_settings` (
  `user_id` int(11) NOT NULL,
  `default_currency` varchar(3) DEFAULT 'RUB',
  `date_format` varchar(20) DEFAULT 'Y-m-d',
  `language` varchar(10) DEFAULT 'ru',
  `notification_enabled` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `user_settings`
--

INSERT INTO `user_settings` (`user_id`, `default_currency`, `date_format`, `language`, `notification_enabled`) VALUES
(1, 'RUB', 'Y-m-d', 'ru', 1),
(3, 'RUB', 'Y-m-d', 'ru', 1);

--
-- Индексы сохранённых таблиц
--

--
-- Индексы таблицы `accounts`
--
ALTER TABLE `accounts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Индексы таблицы `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_created` (`user_id`,`created_at`);

--
-- Индексы таблицы `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_category_type` (`user_id`,`name`,`type`);

--
-- Индексы таблицы `tags`
--
ALTER TABLE `tags`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_tag` (`user_id`,`name`);

--
-- Индексы таблицы `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `account_id` (`account_id`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `idx_user_date` (`user_id`,`transaction_date`),
  ADD KEY `idx_user_type` (`user_id`,`type`);

--
-- Индексы таблицы `transfers`
--
ALTER TABLE `transfers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `from_account_id` (`from_account_id`),
  ADD KEY `to_account_id` (`to_account_id`),
  ADD KEY `template_id` (`template_id`);

--
-- Индексы таблицы `transfer_templates`
--
ALTER TABLE `transfer_templates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `from_account_id` (`from_account_id`),
  ADD KEY `to_account_id` (`to_account_id`);

--
-- Индексы таблицы `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Индексы таблицы `user_settings`
--
ALTER TABLE `user_settings`
  ADD PRIMARY KEY (`user_id`);

--
-- AUTO_INCREMENT для сохранённых таблиц
--

--
-- AUTO_INCREMENT для таблицы `accounts`
--
ALTER TABLE `accounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT для таблицы `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT для таблицы `tags`
--
ALTER TABLE `tags`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT для таблицы `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT для таблицы `transfers`
--
ALTER TABLE `transfers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `transfer_templates`
--
ALTER TABLE `transfer_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Ограничения внешнего ключа сохраненных таблиц
--

--
-- Ограничения внешнего ключа таблицы `accounts`
--
ALTER TABLE `accounts`
  ADD CONSTRAINT `accounts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `audit_log`
--
ALTER TABLE `audit_log`
  ADD CONSTRAINT `audit_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `categories`
--
ALTER TABLE `categories`
  ADD CONSTRAINT `categories_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `tags`
--
ALTER TABLE `tags`
  ADD CONSTRAINT `tags_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `transactions_ibfk_2` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`),
  ADD CONSTRAINT `transactions_ibfk_3` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`);

--
-- Ограничения внешнего ключа таблицы `transfers`
--
ALTER TABLE `transfers`
  ADD CONSTRAINT `transfers_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `transfers_ibfk_2` FOREIGN KEY (`from_account_id`) REFERENCES `accounts` (`id`),
  ADD CONSTRAINT `transfers_ibfk_3` FOREIGN KEY (`to_account_id`) REFERENCES `accounts` (`id`),
  ADD CONSTRAINT `transfers_ibfk_4` FOREIGN KEY (`template_id`) REFERENCES `transfer_templates` (`id`) ON DELETE SET NULL;

--
-- Ограничения внешнего ключа таблицы `transfer_templates`
--
ALTER TABLE `transfer_templates`
  ADD CONSTRAINT `transfer_templates_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `transfer_templates_ibfk_2` FOREIGN KEY (`from_account_id`) REFERENCES `accounts` (`id`),
  ADD CONSTRAINT `transfer_templates_ibfk_3` FOREIGN KEY (`to_account_id`) REFERENCES `accounts` (`id`);

--
-- Ограничения внешнего ключа таблицы `user_settings`
--
ALTER TABLE `user_settings`
  ADD CONSTRAINT `user_settings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
