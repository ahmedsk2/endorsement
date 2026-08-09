-- Least privilege + engine-enforced append-only audit log.
-- OWNER-RUN, once per customer instance, against production. Not run by the app, not run by CI.
--
-- WHY: the mysql image auto-grants the application user ALL PRIVILEGES on its schema —
-- including DROP, and including UPDATE/DELETE on audit_log. So two of this system's
-- stated invariants are, today, only conventions in PHP:
--   * "the audit log is append-only"
--   * "clinical rows are never hard-deleted; accounts are deactivated, never deleted"
-- Anything that can reach the database with the app's own credential can violate both,
-- and the audit trail is the compensating control named against most other risks.
--
-- SUBSTITUTE BEFORE RUNNING. On a customer whose database is not named after the first
-- customer, the old hardcoded form failed HALFWAY: the REVOKE/GRANT errored (no such grant,
-- no such user) while
-- the audit_log triggers still applied to whatever schema the connection had selected. The
-- operator saw the triggers listed by section 3 and concluded it had worked, leaving the
-- runtime credential holding ALL PRIVILEGES — including DROP, and including UPDATE/DELETE on
-- audit_log. The placeholders below make an unsubstituted run a syntax error instead.
--
-- HOW TO RUN (resolves the ONE running stack for <uuid>, or refuses — see
-- docker/instance-env.sh; never select the database container by image ancestry):
--
--   eval "$(sudo bash docker/instance-env.sh <uuid>)" && \
--   sed -e "s/{{DATABASE}}/$DBNAME/g" -e "s/{{USER}}/$DBUSER/g" docs/sql/least-privilege.sql | \
--   sudo docker exec -i "$DB" sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot '"$DBNAME"

-- ---------------------------------------------------------------------------
-- 1. Drop DDL from the runtime user.
-- ---------------------------------------------------------------------------
-- The application never creates or alters a table at runtime: docker/entrypoint.sh
-- deliberately does not migrate at boot, precisely so an unattended restart cannot change
-- a clinical schema. There is therefore no reason for its credential to be able to.
--
-- CONSEQUENCE, and it is deliberate: `php artisan migrate` will no longer work with the
-- app's credential, which keeps schema change an explicit, privileged, human act.
--
-- `docker exec -e DB_USERNAME=root -e DB_PASSWORD=... <app-container> php artisan migrate
-- --force` does NOT work as the way to do that — this was documented here once and
-- contradicted docs/RUNBOOK-DEPLOY.md, which already correctly explained why: this
-- application's config is cached at boot (`php artisan config:cache`), so an already-running
-- PHP-FPM worker never re-consults `env()`, and a runtime `-e` override on `docker exec`
-- against that already-running container has no effect.
--
-- The actual procedure — grant the migration privileges to the APP's own credential
-- TEMPORARILY, run `migrate`, revoke immediately after — is docs/RUNBOOK-DEPLOY.md's
-- "Migrations need a privilege the app does not have", which also carries the recovery
-- procedure for a mid-chain failure (MySQL has no transactional DDL, so a failed migration
-- can leave a partially-created object behind that needs a manual DROP before retrying).
-- The exact grant, verified empirically against the full migration chain on real MySQL 8.4:
--
--   ALTER, CREATE, REFERENCES   -- for `php artisan migrate --force`
--   + DROP                      -- additionally, only for the occasional `migrate:rollback`
--
-- `INDEX` is needed for neither. Keep this file and the runbook's grant in agreement — they
-- describe the same two credentials (this file: the permanent runtime grant, below; the
-- runbook: the temporary migration-window grant) and must never state a different privilege
-- list for the same operation again.
--
REVOKE ALL PRIVILEGES ON `{{DATABASE}}`.* FROM '{{USER}}'@'%';

GRANT SELECT, INSERT, UPDATE, DELETE ON `{{DATABASE}}`.* TO '{{USER}}'@'%';

-- Deliberately NOT granted: CREATE, ALTER, DROP, INDEX, REFERENCES, CREATE TEMPORARY
-- TABLES, LOCK TABLES, TRIGGER, CREATE ROUTINE, ALTER ROUTINE, EXECUTE, FILE, PROCESS,
-- RELOAD, SHOW DATABASES, SUPER.
--
-- PROCESS in particular: mysqldump wants it to dump tablespaces, which is why backup:run
-- passes --no-tablespaces rather than the privilege being widened to suit the backup.

-- ---------------------------------------------------------------------------
-- 2. Make the audit log append-only in the ENGINE, not in the application.
-- ---------------------------------------------------------------------------
-- Grants alone cannot express this cleanly: MySQL privileges are cumulative, so a
-- schema-wide UPDATE grant cannot be revoked for one table. Triggers can, and they hold
-- regardless of which credential is used — including root, and including a restored dump
-- being edited in place.
--
-- This does NOT make the chain unforgeable on its own: anyone holding TRIGGER privilege
-- can drop these. It raises tampering from "a single UPDATE" to "a deliberate, privileged,
-- multi-step act", and it stops the application's own credential entirely. The remaining
-- gap — that the hash chain is unkeyed and recomputable — is tracked as SPC-RPT-004 and
-- needs an HMAC key held outside the database plus an off-box anchor of the daily head.

DROP TRIGGER IF EXISTS `audit_log_is_append_only_update`;
DROP TRIGGER IF EXISTS `audit_log_is_append_only_delete`;

DELIMITER //

CREATE TRIGGER `audit_log_is_append_only_update`
BEFORE UPDATE ON `audit_log`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'audit_log is append-only: rows cannot be modified.';
END//

CREATE TRIGGER `audit_log_is_append_only_delete`
BEFORE DELETE ON `audit_log`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'audit_log is append-only: rows cannot be deleted.';
END//

DELIMITER ;

FLUSH PRIVILEGES;

-- ---------------------------------------------------------------------------
-- 3. Verify (expect: no DDL in the grant line, and both triggers listed)
-- ---------------------------------------------------------------------------
SHOW GRANTS FOR '{{USER}}'@'%';
SELECT TRIGGER_NAME, EVENT_MANIPULATION
  FROM information_schema.TRIGGERS
 WHERE EVENT_OBJECT_TABLE = 'audit_log';

-- Then prove it, on the live system — both of these MUST fail:
--   UPDATE audit_log SET action = 'tampered' WHERE id = 1;
--   DELETE FROM audit_log WHERE id = 1;
