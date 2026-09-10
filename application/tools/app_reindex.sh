#!/bin/sh
php /application/tools/app_migrate.php
source /application/tools/dbinit.sh
echo "Обновление полнотекстовых индексов">>/application/sql/status
$SQL_CMD -f /application/tools/update_vectors.sql
php /application/author_search.php
echo "Создание индекса zip-файлов">>/application/sql/status
php /application/tools/app_update_zip_list.php

echo "Сканирование метаданных книг">>/application/sql/status
php /application/tools/app_worker.php

echo "">/application/sql/status
