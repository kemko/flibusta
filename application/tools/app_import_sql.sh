#!/bin/sh
set -e
. /application/tools/dbinit.sh
trap 'echo "Ошибка импорта. Импорт остановлен." >>/application/sql/status' EXIT
php /application/tools/app_migrate.php

mkdir -p /application/sql/psql
mkdir -p /application/cache/authors
mkdir -p /application/cache/covers
mkdir -p /application/cache/tmp

echo "Распаковка sql.gz">/application/sql/status
for dump in /application/sql/*.sql.gz; do
    [ -f "$dump" ] || continue
    if ! gzip -f -d "$dump" >>/application/sql/status 2>&1; then
        echo "Ошибка распаковки $dump. Импорт остановлен." >>/application/sql/status
        exit 1
    fi
done

for name in lib.a.attached.zip lib.b.attached.zip; do
    [ -f "/application/sql/$name" ] || continue
    echo "Копирование $name в cache" >>/application/sql/status
    if ! tmp=$(mktemp "/application/cache/.$name.XXXXXX") ||
       ! cp "/application/sql/$name" "$tmp" ||
       ! mv -f "$tmp" "/application/cache/$name"; then
        rm -f "$tmp"
        echo "Ошибка копирования $name. Импорт остановлен." >>/application/sql/status
        exit 1
    fi
done

/application/tools/app_topg lib.a.annotations_pics.sql
/application/tools/app_topg lib.b.annotations_pics.sql
/application/tools/app_topg lib.a.annotations.sql
/application/tools/app_topg lib.b.annotations.sql
/application/tools/app_topg lib.libavtorname.sql
if [ -f /application/sql/lib.libavtoraliase.sql ]; then
    /application/tools/app_topg lib.libavtoraliase.sql
fi
/application/tools/app_topg lib.libavtor.sql
/application/tools/app_topg lib.libbook.sql
/application/tools/app_topg lib.libfilename.sql
/application/tools/app_topg lib.libgenrelist.sql
/application/tools/app_topg lib.libgenre.sql
/application/tools/app_topg lib.libjoinedbooks.sql
/application/tools/app_topg lib.librate.sql
/application/tools/app_topg lib.librecs.sql
/application/tools/app_topg lib.libseqname.sql
/application/tools/app_topg lib.libseq.sql
/application/tools/app_topg lib.libtranslator.sql
/application/tools/app_topg lib.reviews.sql

echo "Подчистка БД. Стираем авторов, серии и жанры у которых нет ни одной книги" >>/application/sql/status
$SQL_CMD -f /application/tools/cleanup_db.sql

echo "Обновление полнотекстовых индексов">>/application/sql/status
$SQL_CMD -f /application/tools/update_vectors.sql
php /application/author_search.php

echo "Создание индекса zip-файлов">>/application/sql/status
php /application/tools/app_update_zip_list.php

echo "Сканирование метаданных книг">>/application/sql/status
php /application/tools/app_worker.php

echo "">/application/sql/status
trap - EXIT
