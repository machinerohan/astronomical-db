{
  description = "Astronomical Objects Database — school project dev environment";

  inputs = {
    nixpkgs.url = "github:NixOS/nixpkgs/nixos-24.11";
    flake-utils.url = "github:numtide/flake-utils";
  };

  outputs = { self, nixpkgs, flake-utils }:
    flake-utils.lib.eachDefaultSystem (system:
      let
        pkgs = import nixpkgs { inherit system; };
      in
      {
        devShells.default = pkgs.mkShell {
          name = "astronomical-db";

          packages = [
            pkgs.mariadb       # MySQL-compatible server + client
            pkgs.php           # PHP built-in server for development
          ];

          shellHook = ''
            MYSQLDATA="$(pwd)/.mysql-data"
            MYSQLSOCK="$MYSQLDATA/mysql.sock"
            export MYSQLDATA MYSQLSOCK

            if [ ! -d "$MYSQLDATA/mysql" ]; then
              mkdir -p "$MYSQLDATA"
              mysql_install_db --datadir="$MYSQLDATA" --auth-root-authentication-method=normal >/dev/null 2>&1
              echo "  ✓ MySQL data directory initialized"
            fi

            alias mysqld='mysqld --datadir="$MYSQLDATA" --socket="$MYSQLSOCK" --port=3306 --bind-address=127.0.0.1'
            alias mysql='mysql --socket="$MYSQLSOCK"'
            alias mysqladmin='mysqladmin --socket="$MYSQLSOCK"'

            echo "Astronomical DB dev shell"
            echo "  mysqld &    — start MySQL server (background)"
            echo "  mysql       — connect with project-local socket"
            echo "  mysqladmin shutdown — stop the server"
            echo "  php -S localhost:8080 -t htdocs/  — start PHP dev server"
            echo "  run: mysql < db/schema.sql  to load the schema"
          '';
        };
      });
}
