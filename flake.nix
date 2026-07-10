{
  description = "AstroForum — astronomy discussion forum with community-curated catalogue";

  inputs = {
    nixpkgs.url = "github:NixOS/nixpkgs/nixos-24.11";
    flake-utils.url = "github:numtide/flake-utils";
  };

  outputs = { self, nixpkgs, flake-utils }:
    flake-utils.lib.eachDefaultSystem (system:
      let
        pkgs = import nixpkgs { inherit system; };

        php = pkgs.php.withExtensions ({ all, ... }: with all; [
          ctype
          dom
          filter
          json
          mbstring
          pdo
          pdo_mysql
          session
          tokenizer
          xmlwriter
        ]);
      in
      {
        devShells.default = pkgs.mkShell {
          name = "astroforum";

          packages = [
            pkgs.mariadb
            php
          ];

          shellHook = ''
            PRJ="$(pwd -P)"
            MYSQLDATA="$PRJ/.mysql-data"
            MYSQLSOCK="$MYSQLDATA/mysql.sock"
            export MYSQLDATA MYSQLSOCK

            if [ -d "$PRJ/db" ] && [ ! -d "$MYSQLDATA/mysql" ]; then
              mkdir -p "$MYSQLDATA"
              mysql_install_db --datadir="$MYSQLDATA" --auth-root-authentication-method=normal >/dev/null 2>&1
              echo "  ✓ MariaDB data directory initialized"
            fi

            alias mysqld='mysqld --datadir="$MYSQLDATA" --socket="$MYSQLSOCK" --port=3306 --bind-address=127.0.0.1'
            alias mysql='mysql --socket="$MYSQLSOCK"'
            alias mysqladmin='mysqladmin --socket="$MYSQLSOCK"'

            echo ""
            echo "╔═══════════════════════════════════════════╗"
            echo "║  AstroForum  —  dev environment           ║"
            echo "╠═══════════════════════════════════════════╣"
            echo "║  mysqld &      start MariaDB (background) ║"
            echo "║  mysqladmin    shutdown                    ║"
            echo "║  mysql < db/schema.sql   load catalogue   ║"
            echo "║  mysql < htdocs/schema-forum.sql  forum   ║"
            echo "║  mysql < htdocs/schema-forum-seed.sql     ║"
            echo "║  php -S localhost:8080 -t htdocs/         ║"
            echo "╚═══════════════════════════════════════════╝"
          '';
        };
      });
}
