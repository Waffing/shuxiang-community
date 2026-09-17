from pathlib import Path


root = Path(__file__).resolve().parents[1]
schema = (root / "app/database/schema.sql").read_text(encoding="utf-8")
migration_files = sorted((root / "app/database/migrations").glob("*.sql"))
compose = (root / "docker-compose.yml").read_text(encoding="utf-8")
dev_compose = (root / "docker-compose.dev.yml").read_text(encoding="utf-8")
deploy = (root / "deploy.sh").read_text(encoding="utf-8")
migrate = (root / "migrate.sh").read_text(encoding="utf-8")
seed = (root / "app/database/seed.sql").read_text(encoding="utf-8")

assert not list((root / "app/database").glob("20*.sql"))
assert migration_files
assert "CREATE TABLE IF NOT EXISTS schema_migrations" in schema
assert "20260727_backend_enhancements" in schema
assert "20260728_resource_verifications" in schema
assert "CREATE TABLE IF NOT EXISTS favorites" in schema
assert "CREATE TABLE IF NOT EXISTS point_rewards" in schema
assert "CREATE TABLE IF NOT EXISTS resource_verifications" in schema
assert root / "app/database/migrations/20260728_resource_verifications.sql" in migration_files
assert schema.index("CREATE TABLE IF NOT EXISTS resource_verifications") < schema.index("INSERT IGNORE INTO schema_migrations")

for migration_file in migration_files:
    sql = migration_file.read_text(encoding="utf-8").upper()
    assert "CREATE TABLE IF NOT EXISTS" in sql
    assert "DROP TABLE" not in sql
    assert "TRUNCATE" not in sql

assert "schema.sql:/docker-entrypoint-initdb.d/001_schema.sql:ro" in compose
assert "seed.sql:/docker-entrypoint-initdb.d/002_seed.sql:ro" not in compose
assert "seed.sql:/docker-entrypoint-initdb.d/002_seed.sql:ro" in dev_compose
assert seed.startswith("-- DEVELOPMENT/TEST EXAMPLE DATA ONLY.")

assert deploy.index("backup_existing_database") < deploy.index("git pull --ff-only")
assert deploy.index("bash ./migrate.sh") < deploy.index("build --pull app nginx backup")
assert migrate.index('exec -T db sh -lc "$database_command" < "$file"') < migrate.index("INSERT INTO schema_migrations")
assert "Migration failed and was not recorded" in migrate

print("Migration structure tests passed")
