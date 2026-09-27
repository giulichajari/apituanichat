CREATE TABLE IF NOT EXISTS chat_message_features (
 message_id INT PRIMARY KEY, reply_to INT NULL, forwarded_from INT NULL,
 edited_at BIGINT NULL, deleted_at BIGINT NULL, view_once TINYINT NOT NULL DEFAULT 0,
 attachment_id BIGINT NULL, request_owner INT NULL, request_key VARCHAR(80) NULL,
 request_digest CHAR(64) NULL, UNIQUE KEY chat_request(request_owner,request_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS chat_message_users (
 message_id INT NOT NULL, user_id INT NOT NULL, delivered_at BIGINT NULL, read_at BIGINT NULL,
 hidden_at BIGINT NULL, favorite TINYINT NOT NULL DEFAULT 0, reaction VARCHAR(32) NULL,
 PRIMARY KEY(message_id,user_id), KEY personal_messages(user_id,favorite,message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS chat_private_files (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, owner_id INT NOT NULL,
 storage_key CHAR(48) NOT NULL UNIQUE, original_name VARCHAR(255) NOT NULL,
 mime VARCHAR(100) NOT NULL, size BIGINT NOT NULL, created_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS chat_view_once (
 message_id INT PRIMARY KEY, sender_id INT NOT NULL, recipient_id INT NOT NULL,
 body LONGTEXT NULL, created_at BIGINT NOT NULL, consumed_at BIGINT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS chat_notes (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, owner_id INT NOT NULL,
 message_id INT NULL, body TEXT NOT NULL, created_at BIGINT NOT NULL,
 KEY personal_notes(owner_id,id), UNIQUE KEY note_source(owner_id,message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS chat_scheduled_tasks (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, owner_id INT NOT NULL,
 request_key VARCHAR(80) NOT NULL, request_digest CHAR(64) NOT NULL,
 kind VARCHAR(16) NOT NULL, chat_id INT NULL, body TEXT NOT NULL,
 due_at BIGINT NOT NULL, timezone VARCHAR(80) NOT NULL, status VARCHAR(16) NOT NULL,
 attempts INT NOT NULL DEFAULT 0, next_attempt_at BIGINT NOT NULL DEFAULT 0,
 created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, result_id BIGINT NULL, last_error VARCHAR(40) NULL, acknowledged_at BIGINT NULL,
 UNIQUE KEY scheduled_request(owner_id,request_key), KEY due_tasks(status,due_at,next_attempt_at), KEY owner_tasks(owner_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS chat_activity (
 chat_id INT NOT NULL,user_id INT NOT NULL,activity VARCHAR(16) NOT NULL,expires_at BIGINT NOT NULL,
 PRIMARY KEY(chat_id,user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS chat_call_history (
 call_id VARCHAR(120) PRIMARY KEY,caller_id INT NOT NULL,callee_id INT NOT NULL,
 call_type VARCHAR(16) NOT NULL,status VARCHAR(16) NOT NULL,started_at BIGINT NOT NULL,
 answered_at BIGINT NULL,ended_at BIGINT NULL,reason VARCHAR(80) NULL,revision BIGINT NOT NULL,
 KEY caller_history(caller_id,started_at),KEY callee_history(callee_id,started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
