#!/bin/sh
php /application/tools/app_migrate.php
echo "Создание индекса zip-файлов">>/application/sql/status
php /application/tools/app_update_zip_list.php

echo "Сканирование метаданных книг">>/application/sql/status
php /application/tools/app_worker.php

echo "">/application/sql/status
