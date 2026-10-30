"""Translate database/schema.sql (MySQL dialect) to SQLite for local testing only."""
import re, sys
src = open(sys.argv[1]).read()
out = re.sub(r"\)\s*ENGINE=InnoDB[^;]*;", ");", src)
out = re.sub(r"INT AUTO_INCREMENT PRIMARY KEY", "INTEGER PRIMARY KEY AUTOINCREMENT", out)
out = re.sub(r"ENUM\([^)]*\)", "TEXT", out)
open(sys.argv[2], "w").write(out)
