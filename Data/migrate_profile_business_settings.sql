ALTER TABLE profiles
    ADD COLUMN enable_welcome_message TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN welcome_message TEXT NULL,
    ADD COLUMN welcome_link VARCHAR(500) NULL,
    ADD COLUMN company_description TEXT NULL,
    ADD COLUMN enable_unavailable_auto_reply TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN unavailable_auto_reply_message TEXT NULL;
