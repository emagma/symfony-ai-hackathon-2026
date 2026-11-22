# The MCP server's own database and user, next to the shop's, on the one
# MariaDB server.
#
# Sourced by the MariaDB entrypoint (docker-entrypoint-initdb.d) ON A FRESH
# VOLUME ONLY, with the container's environment: a volume created before this
# file existed never runs it. `castor mcp:db` repairs that case - the grant
# below is what makes `doctrine:database:create --if-not-exists` work there.

docker_process_sql <<-SQL
	CREATE DATABASE IF NOT EXISTS \`${MCP_DATABASE_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
	CREATE USER IF NOT EXISTS '${MCP_DATABASE_USER}'@'%' IDENTIFIED BY '${MCP_DATABASE_PASSWORD}';
	GRANT ALL PRIVILEGES ON \`${MCP_DATABASE_NAME}\`.* TO '${MCP_DATABASE_USER}'@'%';
	FLUSH PRIVILEGES;
SQL
