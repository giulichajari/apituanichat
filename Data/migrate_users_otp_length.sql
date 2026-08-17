-- El token de recuperación de contraseña (32 hex) no entra en otp si es VARCHAR(6).
ALTER TABLE users MODIFY COLUMN otp VARCHAR(64) NULL;
