# Вариант 21. Fedora + Nginx + MariaDB + XML API

Пошаговый гайд от создания виртуальной машины до демонстрации API. Подготовлено 20 ноября 2026 года.

## 1. Результат лабораторной

Нужно реализовать **API, возвращающее XML по GET-запросу**, для предметной области «Остатки на складе лекарств». Простейший UI показывает данные, полученные именно из API, и позволяет открыть или скачать XML.

| Что открываем | Адрес |
|---|---|
| Страница с таблицей | `http://IP-Fedora/` |
| API с XML | `http://IP-Fedora/api/stock` |
| Тот же XML как скачиваемый файл | `http://IP-Fedora/api/stock?download=1` |

Рабочая схема стенда выглядит так.

```text
Браузер / curl
      │ GET /api/stock
      ▼
Nginx, порт 80
      │ FastCGI через локальный Unix-сокет
      ▼
PHP-FPM → PHP-обработчик → SELECT из MariaDB
      │
      └── XML, HTTP 200, Content-Type: application/xml; charset=UTF-8
```

PHP выбран как простой способ связать Nginx и MariaDB. Данные в API каждый раз читаются из таблицы. UI вызывает API через `fetch`, разбирает XML и показывает шесть столбцов.

| Поле задания | Поле БД и XML | Тип MariaDB |
|---|---|---|
| Идентификатор остатка | `balance_id` | `INT UNSIGNED`, первичный ключ |
| Название препарата | `drug_name` | `VARCHAR(200)` |
| Номер партии | `batch_number` | `VARCHAR(100)` |
| Срок годности | `expiry_date` | `DATE` |
| Количество единиц | `quantity` | `INT UNSIGNED` |
| Температура хранения | `storage_temperature` | `VARCHAR(100)` |

Номер партии хранится текстом, чтобы сохранить ведущие нули. Температура тоже хранится текстом, например `+2…+8 °C`, поскольку условие допускает описание диапазона. В комплект включены три вымышленные учебные записи.

## 2. Что скачать и подготовить на Windows

Для инструкции выбран **Oracle VirtualBox**. Если он уже используется для предыдущих ВМ, создайте в нём новую машину.

1. При необходимости установите VirtualBox для **Windows hosts** с официальной страницы. [Загрузка VirtualBox](https://www.virtualbox.org/wiki/Downloads).
2. Скачайте **Fedora Workstation 45 Live ISO для Intel/AMD x86_64**. На момент подготовки это актуальный выпуск Workstation, вышедший 3 ноября 2026 года. [Загрузка Fedora](https://fedoraproject.org/workstation/download/).
3. Сохраните рядом приложенный архив **`pharmacy-lab.zip`**. В нём находятся код API, простой UI, SQL и конфигурации.

Workstation выбрана для удобной первой установки с графическим рабочим столом. Для этой лабораторной используется обычная редакция с пакетным менеджером DNF.

## 3. Создать Fedora VM в VirtualBox

В VirtualBox нажмите **New / Создать** и задайте параметры.

| Параметр | Значение |
|---|---|
| Name | `Fedora-Lab21` |
| ISO Image | скачанный Fedora Workstation x86_64 ISO |
| Type / OS | Linux |
| Version | Fedora (64-bit), если доступно |
| Unattended Installation | выбрать **Skip Unattended Installation**, если этот пункт показан |
| RAM | **4096 MB**, либо 6144 MB при достаточной памяти компьютера |
| CPU | **2** виртуальных процессора |
| Virtual Hard Disk | создать новый диск **40 GB** |
| Формат диска | VDI, динамический размер, если мастер предлагает выбор |

После создания откройте **Settings → Display**. Для Linux используйте **Graphics Controller = VMSVGA**, Video Memory — **128 MB**. При проблемах с изображением оставьте 3D Acceleration выключенной.

В **Settings → Network → Adapter 1** сначала выберите **NAT** и включите **Cable Connected**. Через NAT удобно выполнить установку и загрузить пакеты.

Запустите ВМ кнопкой **Start**.

## 4. Установить Fedora на виртуальный диск

1. В меню загрузки выберите запуск Fedora Workstation Live. Дождитесь рабочего стола.
2. Запустите **Install Fedora / Install to Hard Drive**. Названия страниц установщика могут немного отличаться между образами.
3. Выберите язык **English**, при необходимости добавьте русскую раскладку клавиатуры.
4. В качестве места установки выберите **созданный виртуальный диск 40 GB**. Используйте автоматическую разметку или вариант использования всего выбранного диска.
5. Укажите часовой пояс **Europe/Moscow**, если установщик предлагает этот шаг.
6. Подтвердите сводку и начните установку. Дождитесь её завершения.
7. Перезагрузите ВМ. В VirtualBox извлеките ISO через **Devices → Optical Drives → Remove disk from virtual drive**, чтобы загрузилась установленная система.
8. В мастере первого запуска создайте пользователя **student** и свой пароль. Если имя пользователя будет другим, заменяйте `student` в командах подключения ниже.

Откройте приложение **Terminal** через поиск приложений. Сначала проверьте систему.

```bash
cat /etc/fedora-release
uname -m
whoami
```

Ожидаются Fedora 45, архитектура `x86_64` и ваше имя пользователя.

Обновите систему.

```bash
sudo dnf upgrade --refresh -y
sudo reboot
```

При вводе пароля для `sudo` символы в Terminal не отображаются. После перезагрузки снова откройте Terminal.

## 5. Установить серверные пакеты

Выполните внутри Fedora.

```bash
sudo hostnamectl set-hostname fedora-lab21
sudo dnf install -y nginx mariadb-server mariadb php-fpm php-cli php-mysqlnd php-xml policycoreutils-python-utils openssh-server unzip curl
sudo systemctl enable --now mariadb php-fpm nginx sshd firewalld
```

Проверьте версии и службы.

```bash
nginx -v
mariadb --version
php -v
php -m | grep -E 'PDO|pdo_mysql|dom'
systemctl is-active mariadb php-fpm nginx sshd firewalld
```

У всех пяти служб ожидается `active`. `php-mysqlnd` предоставляет PHP-драйвер для MariaDB/MySQL, а `php-xml` — инструменты формирования XML.

Используем пакеты из репозиториев Fedora. Фактические номера Nginx, MariaDB и PHP запишите в отчёт по выводу команд.

[Пакет Nginx в Fedora](https://packages.fedoraproject.org/pkgs/nginx/nginx/), [PHP-FPM](https://packages.fedoraproject.org/pkgs/php/php-fpm/), [драйвер PHP MySQL](https://packages.fedoraproject.org/pkgs/php/php-mysqlnd/).

## 6. Настроить сетевой мост

1. Завершите работу Fedora через системное меню **Power Off**.
2. В VirtualBox откройте **Settings → Network → Adapter 1**.
3. Измените **Attached to = Bridged Adapter / Сетевой мост**.
4. В **Name** выберите адаптер основного компьютера, через который сейчас работает интернет — Wi-Fi или Ethernet.
5. Оставьте **Cable Connected** включённым и запустите Fedora.

В Fedora узнайте адрес.

```bash
ip -br -4 addr
ip route
```

Выберите IPv4 основного сетевого интерфейса. Далее `192.168.1.42` — **пример**, вместо него вводите свой IP Fedora.

Откройте HTTP и SSH в firewalld.

```bash
sudo firewall-cmd --get-active-zones
sudo firewall-cmd --get-default-zone
sudo firewall-cmd --permanent --add-service=http
sudo firewall-cmd --permanent --add-service=ssh
sudo firewall-cmd --reload
sudo firewall-cmd --list-services
```

Эти команды добавляют правила в зону по умолчанию. Если сетевой интерфейс в первой команде привязан к другой зоне, добавляйте `--zone=ИМЯ_АКТИВНОЙ_ЗОНЫ` к командам добавления сервисов и проверки.

В браузере Windows откройте `http://192.168.1.42/`. Пока должна появиться стандартная страница Nginx.

[Сетевые режимы VirtualBox](https://docs.oracle.com/en/virtualization/virtualbox/7.2/user/networkingdetails.html), [открытие сервисов firewalld](https://firewalld.org/documentation/howto/open-a-port-or-service.html).

### Если в сети аудитории мост не получает адрес

Можно выполнить работу через NAT с перенаправлением портов.

1. Выключите Fedora, верните **Adapter 1 → NAT**.
2. Откройте **Advanced → Port Forwarding** и добавьте правила.

| Name | Protocol | Host IP | Host Port | Guest IP | Guest Port |
|---|---|---|---|---|---|
| web | TCP | `127.0.0.1` | `8082` | пусто | `80` |
| ssh | TCP | `127.0.0.1` | `2222` | пусто | `22` |

После запуска ВМ сайт будет доступен на основном компьютере по `http://127.0.0.1:8082/`, API — по `http://127.0.0.1:8082/api/stock`. Для SSH используйте порт 2222. Такой способ позволяет показать работу с основного компьютера. Для подключения с других устройств сети используйте работающий мост.

## 7. Скачать проект из GitHub в Fedora

Начиная с этого пункта файлы получаем через **Git**. Все команды выполняются **в Fedora Terminal**. Сначала дождитесь завершения текущего `dnf upgrade` и возвращения приглашения терминала. Если обновлялось ядро, перезагрузите Fedora.

Репозиторий проекта: [vladpolisuk/pharmacy-stock-xml-api](https://github.com/vladpolisuk/pharmacy-stock-xml-api). На время передачи он **публичный**, поэтому для скачивания вход в GitHub не требуется.

### 7.1. Если проекта ещё нет в Fedora

Установите Git.

```bash
sudo dnf install -y git
git --version
```

Если снова возникает ошибка загрузки `openh264`, повторите установку Git с временным отключением репозитория Cisco.

```bash
sudo dnf --disable-repo=fedora-cisco-openh264 install -y git
```

Скачайте код в домашний каталог текущего пользователя.

```bash
git clone https://github.com/vladpolisuk/pharmacy-stock-xml-api.git "$HOME/pharmacy-lab"
```

`git clone` выполняется **один раз**. `$HOME` автоматически подставляет домашний каталог вашего пользователя, например `/home/vladpolisuk`, независимо от имени пользователя в предыдущих примерах.

### 7.2. Если репозиторий уже клонирован

Для получения опубликованных изменений выполните:

```bash
git -C "$HOME/pharmacy-lab" pull --ff-only
```

Сообщение `Already up to date.` означает, что последняя версия уже скачана. Если в каталоге есть собственные правки и Git отказывается обновляться, сохраните их перед обновлением. Не удаляйте каталог для повторного клонирования.

### 7.3. Проверить скачанные файлы

```bash
git -C "$HOME/pharmacy-lab" status --short --branch
ls "$HOME/pharmacy-lab"
```

Ожидается ветка `main`, связанная с `origin/main`, и следующая структура.

```text
pharmacy-lab/
  .git/              локальная история Git
  public/
    index.html       простой UI
    app.js           GET-запрос к API и построение таблицы
    style.css        оформление таблицы
    api.php          обработчик API
  src/
    stock.php        запрос к MariaDB и формирование XML
  database/
    schema.sql       база, таблица, тестовые данные, пользователь SELECT
  config/
    config.php       настройки подключения к БД
    nginx.conf       конфигурация Nginx
    pharmacy-pool.conf
    mariadb-local.cnf
  example.xml        пример структуры XML
  README.md         описание проекта и запросов
  GUIDE_RU.md        этот гайд
```

Репозиторий используется для одноразовой передачи. После подтверждения получения кода его можно удалить с GitHub: локальные файлы в Fedora и работающий API сохранятся. До удаления доступны `clone` и `pull`; после удаления дальнейшее скачивание из этого репозитория станет недоступно.

Продолжайте с пункта 8. [Документация `git clone`](https://git-scm.com/docs/git-clone), [документация `git pull`](https://git-scm.com/docs/git-pull).

## 8. Создать базу MariaDB

В командах этого и следующих разделов исходники находятся в **`$HOME/pharmacy-lab`**, куда их скачал Git. Текущий каталог терминала может быть любым.

Задайте локальный адрес прослушивания MariaDB.

```bash
sudo install -m 0644 "$HOME/pharmacy-lab/config/mariadb-local.cnf" /etc/my.cnf.d/pharmacy-lab.cnf
sudo systemctl restart mariadb
```

Выполните SQL-файл **один раз для новой базы**.

```bash
sudo mariadb --default-character-set=utf8mb4 < "$HOME/pharmacy-lab/database/schema.sql"
```

SQL создаёт базу `pharmacy`, таблицу `stock_balances`, три учебные записи и пользователя `pharmacy_reader` с правом **SELECT** на эту таблицу.

Проверьте данные администратором.

```bash
sudo mariadb --default-character-set=utf8mb4 pharmacy -e "SELECT * FROM stock_balances;"
sudo mariadb -e "SHOW GRANTS FOR 'pharmacy_reader'@'127.0.0.1';"
```

Ожидаются три записи и разрешение SELECT. Сверьте все шесть полей задания с таблицей из раздела 1.

Для отдельной проверки подключения пользователя API выполните команду.

```bash
mariadb --protocol=TCP -h 127.0.0.1 -u pharmacy_reader -p pharmacy -e "SELECT COUNT(*) FROM stock_balances;"
```

Учебный пароль из SQL-файла — **`Lab21-Med-2026!`**. Ожидаемый результат — `3`. Если меняете пароль, измените его и в настройках PHP следующего раздела.

Если `sudo mariadb` на вашей установке запрашивает пароль администратора, используйте `sudo mariadb -u root -p` и пароль, который вы задали при настройке MariaDB. На новой стандартной установке сначала проверяется команда без `-p`.

При повторном выполнении `schema.sql` сообщение `database exists` означает, что база уже создана. Продолжайте с проверок существующей базы.

[Права пользователей MariaDB](https://mariadb.com/docs/server/reference/sql-statements/account-management-sql-statements/grant).

## 9. Разместить приложение и настройки подключения

В веб-каталог копируются `public` и `src` из клонированного проекта. История `.git`, SQL и гайд остаются в домашнем каталоге.

```bash
sudo install -d -m 0755 /var/www/pharmacy
sudo cp -r "$HOME/pharmacy-lab/public" "$HOME/pharmacy-lab/src" /var/www/pharmacy/
sudo chown -R root:root /var/www/pharmacy
sudo find /var/www/pharmacy -type d -exec chmod 0755 {} \;
sudo find /var/www/pharmacy -type f -exec chmod 0644 {} \;
sudo install -d -o root -g nginx -m 0750 /etc/pharmacy-lab
sudo install -o root -g nginx -m 0640 "$HOME/pharmacy-lab/config/config.php" /etc/pharmacy-lab/config.php
```

Настройки подключения лежат в `/etc/pharmacy-lab/config.php`. При необходимости откройте их командой `sudo nano /etc/pharmacy-lab/config.php`.

```php
<?php
return [
    'dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=pharmacy;charset=utf8mb4',
    'username' => 'pharmacy_reader',
    'password' => 'Lab21-Med-2026!',
];
```

Веб-корень сайта будет `/var/www/pharmacy/public`. Настройки подключения находятся отдельно и читаются PHP.

Если стенд уже настроен и нужно обновить API и веб-интерфейс, выполните:

```bash
git -C "$HOME/pharmacy-lab" pull --ff-only
sudo bash "$HOME/pharmacy-lab/scripts/apply.sh"
```

Скрипт сохраняет прежние каталоги `public` и `src` в `/var/backups/pharmacy-lab/`, проверяет конфигурации, устанавливает API и интерфейс, восстанавливает SELinux-контексты и перезагружает PHP-FPM. При ошибке установки восстанавливаются прежние файлы приложения. База и настройки подключения в `/etc/pharmacy-lab/config.php` сохраняются. Сам `git pull` обновляет каталог исходников; установка в веб-каталог выполняется отдельной командой с `sudo`.

## 10. Настроить PHP-FPM и SELinux

Добавьте отдельный пул `pharmacy`.

```bash
sudo install -m 0644 "$HOME/pharmacy-lab/config/pharmacy-pool.conf" /etc/php-fpm.d/pharmacy.conf
sudo php-fpm -t
```

В пуле указан пользователь **nginx**, сокет **`/run/php-fpm/pharmacy.sock`**, владелец сокета **nginx** и права **0660**.

Назначьте SELinux-контексты файлам приложения и настройкам.

```bash
sudo semanage fcontext -a -t httpd_sys_content_t '/var/www/pharmacy(/.*)?'
sudo semanage fcontext -a -t httpd_config_t '/etc/pharmacy-lab(/.*)?'
sudo restorecon -Rv /var/www/pharmacy /etc/pharmacy-lab
sudo setsebool -P httpd_can_network_connect_db on
sudo systemctl restart php-fpm
```

Проверьте результат.

```bash
getenforce
sudo ls -l /run/php-fpm/pharmacy.sock
sudo ls -lZ /etc/pharmacy-lab/config.php
```

SELinux остаётся в состоянии **Enforcing**, а сокет должен принадлежать `nginx:nginx`. При повторной настройке сообщение `File context ... already defined` означает, что правило уже существует. Для изменения существующего правила используйте `semanage fcontext -m` с теми же параметрами вместо `-a`.

[Конфигурация пулов PHP-FPM](https://www.php.net/manual/en/install.fpm.configuration.php), [контексты веб-каталогов в документации Red Hat](https://docs.redhat.com/en/documentation/red_hat_enterprise_linux/9/html/deploying_web_servers_and_reverse_proxies/setting-up-and-configuring-nginx_deploying-web-servers-and-reverse-proxies).

## 11. Подключить приложение к Nginx

На этой **новой учебной ВМ** сохраните исходную конфигурацию Nginx и установите конфигурацию проекта.

```bash
sudo cp -a /etc/nginx/nginx.conf /etc/nginx/nginx.conf.before-lab21
sudo install -m 0644 "$HOME/pharmacy-lab/config/nginx.conf" /etc/nginx/nginx.conf
sudo restorecon -v /etc/nginx/nginx.conf /etc/php-fpm.d/pharmacy.conf
sudo nginx -t
sudo systemctl restart nginx
```

Ожидается сообщение об успешной проверке конфигурации. Основная маршрутизация из файла выглядит так.

```nginx
root /var/www/pharmacy/public;
index index.html;

location = /api/stock {
    include /etc/nginx/fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /var/www/pharmacy/public/api.php;
    fastcgi_pass unix:/run/php-fpm/pharmacy.sock;
}
```

Это точный маршрут **`/api/stock` без завершающего `/`**. Nginx передаёт запрос PHP-FPM, а `/` открывает простую HTML-страницу. Входите в API по этому URL.

[Передача запросов в FastCGI из Nginx](https://nginx.org/en/docs/http/ngx_http_fastcgi_module.html).

## 12. Проверить API внутри Fedora

### 12.1. Проверить настоящий GET-запрос

```bash
curl -i http://127.0.0.1/api/stock
```

В начале ответа должны быть следующие значения.

```http
HTTP/1.1 200 OK
Content-Type: application/xml; charset=UTF-8
```

Далее идёт XML с тремя записями. Сокращённый пример одной записи выглядит так.

```xml
<?xml version="1.0" encoding="UTF-8"?>
<stock_balances>
  <stock_balance>
    <balance_id>1</balance_id>
    <drug_name>Учебный препарат А</drug_name>
    <batch_number>00021-A</batch_number>
    <expiry_date>2027-12-31</expiry_date>
    <quantity>120</quantity>
    <storage_temperature>+2…+8 °C</storage_temperature>
  </stock_balance>
</stock_balances>
```

У каждого остатка присутствуют все шесть полей. Дата передаётся в формате `YYYY-MM-DD`.

### 12.2. Проверить скачивание XML

```bash
curl -f -o "$HOME/medicine-stock.xml" 'http://127.0.0.1/api/stock?download=1'
cat "$HOME/medicine-stock.xml"
```

Проверить структуру можно встроенными средствами Python.

```bash
python3 -c 'import pathlib, xml.etree.ElementTree as E; root=E.parse(pathlib.Path.home()/"medicine-stock.xml").getroot(); print(root.tag, "записей:", len(root))'
```

Ожидается `stock_balances записей: 3`.

### 12.3. Проверить ограничение метода

```bash
curl -i -X POST http://127.0.0.1/api/stock
```

Ожидается **HTTP 405 Method Not Allowed** и XML с кодом `method_not_allowed`. API также поддерживает HEAD для проверки заголовков.

| Ситуация | HTTP | Тело ответа |
|---|---|---|
| Получены данные | `200` | `<stock_balances>…</stock_balances>` |
| Таблица пустая | `200` | `<stock_balances/>` |
| Некорректный URI-параметр | `400` | XML `<error>` с кодом `invalid_parameter` |
| Запрошен неподдерживаемый метод | `405` | XML `<error>` с кодом `method_not_allowed` |
| Ошибка подключения или чтения БД | `503` | XML `<error>` с кодом `database_unavailable` |

### 12.4. Проверить параметризованные GET-запросы

Параметры в URI задают фильтры. Они передаются после `?`, несколько условий соединяются через `&` и применяются одновременно.

| Параметр | Что выбирает |
|---|---|
| `balance_id=1` | Остаток с идентификатором 1 |
| `drug_name=препарат` | Названия, содержащие текст без учёта регистра |
| `batch_number=00021-A` | Точное совпадение номера партии |
| `storage_temperature=+2…+8 °C` | Точное совпадение температуры |
| `min_quantity=1` | Количество не меньше 1 |
| `max_quantity=100` | Количество не больше 100 |
| `expiry_after=2027-01-01` | Срок годности не раньше указанной даты |
| `expiry_before=2027-12-31` | Срок годности не позже указанной даты |
| `download=1` | Скачивание XML с учётом переданных фильтров |

Все границы диапазонов включаются в результат. Без фильтров API возвращает всю таблицу.

```bash
curl -i 'http://127.0.0.1/api/stock?balance_id=1'
curl -i 'http://127.0.0.1/api/stock?min_quantity=1&expiry_before=2027-12-31'
curl --get --data-urlencode 'drug_name=препарат' 'http://127.0.0.1/api/stock'
curl -f -o "$HOME/selected-stock.xml" 'http://127.0.0.1/api/stock?balance_id=2&download=1'
curl -i 'http://127.0.0.1/api/stock?min_quantity=-1'
```

На исходных данных ожидаются: одна запись с ID 1; одна запись с положительным количеством и сроком до конца 2027 года; две записи с текстом «препарат»; XML-файл с записью ID 2; HTTP 400 и XML `invalid_parameter` для отрицательного количества.

Даты проверяются как действительные календарные даты `YYYY-MM-DD`. Количество допускает 0, идентификатор начинается с 1; оба ограничены значением 4294967295. Пустые фильтры пропускаются. Неизвестные параметры, массивы и перевёрнутые диапазоны возвращают HTTP 400. Если совпадений нет, ответ остаётся HTTP 200 с пустым `<stock_balances/>`.

Внутри API условия SQL выбираются из фиксированного списка, а значения привязываются через PDO `prepare` и `bindValue`. В поиск по названию нельзя внедрить SQL; `%` и `_` ищутся как буквальные символы. [Подготовленные запросы PDO](https://www.php.net/manual/en/pdo.prepare.php).

Проверки на исходных трёх учебных записях запускаются без дополнительных Python-пакетов:

```bash
python3 "$HOME/pharmacy-lab/tests/test_api.py"
```

## 13. Открыть UI и API с Windows

Для вашей настройки **NAT** откройте на Windows **`http://127.0.0.1:8082/`**. Если страница уже была открыта до обновления, нажмите **Ctrl+F5**.

На странице «Проверка XML API» два блока:

1. **Сформировать GET-запрос** — восемь полей фильтров, переключатель `download=1` и точный адрес будущего запроса.
2. **Ответ сервера** — HTTP-статус, число записей и вкладки **XML** / **View**. XML показывает исходный ответ с подсветкой, View — таблицу с шестью полями из того же ответа. Заголовки доступны в раскрывающемся блоке **Заголовки ответа**. Переключение вкладок не выполняет новый запрос к API.

Проверьте готовые примеры. Каждая кнопка заполняет поля и сразу отправляет запрос в API.

| Кнопка | Ожидаемый результат на учебной базе |
|---|---|
| **Все записи** | HTTP 200, XML с тремя записями и три строки таблицы |
| **ID 1** | В URL `balance_id=1`, одна запись с ID 1 |
| **ID 2** | В URL `balance_id=2`, одна запись с ID 2 |
| **Два фильтра** | В URL `min_quantity=1&expiry_before=2027-12-31`, одна запись с ID 1 |
| **Нет совпадений** | HTTP 200, пустой `<stock_balances/>`, таблица без строк |
| **Ошибка 400** | В URL `min_quantity=-1`, HTTP 400, XML с кодом `invalid_parameter` |
| **Экспорт XML** | Запись ID 2, в URL `download=1`, в заголовках `Content-Disposition: attachment` |

Для собственного запроса заполните поля и нажмите **Выполнить GET**. Пока вы редактируете поля, адрес в первом блоке обновляется и появляется предупреждение о необходимости выполнить новый GET. Во втором блоке до выполнения запроса остаётся предыдущий ответ. Пустые поля пропускаются. Например, введите в название «препарат» и отправьте запрос: должны вернуться две записи.

Количество записей показывается один раз. Для пустого результата выводится **Записей: 0**, для ошибки — **Записей: —**. Вкладка XML сохраняет исходный XML ошибки, вкладка View показывает её сообщение вместо таблицы.

Ссылка **Открыть XML отдельно** открывает API напрямую. **Скачать XML** сохраняет файл с текущими фильтрами. После примера **Экспорт XML** файл должен содержать только запись ID 2.

Сам API доступен по `http://127.0.0.1:8082/api/stock`. Для просмотра заголовков и тела в Windows PowerShell используйте `curl.exe`.

```powershell
curl.exe -i "http://127.0.0.1:8082/api/stock?balance_id=1"
```

Внутри Fedora открывается `http://127.0.0.1/`, без порта 8082. Если позднее используете сетевой мост, на Windows подставьте IP Fedora вместо `127.0.0.1:8082`.

## 14. Показать, что API читает MariaDB

В Fedora измените количество в первой учебной записи через административный SQL-клиент.

```bash
sudo mariadb pharmacy -e "UPDATE stock_balances SET quantity=125 WHERE balance_id=1;"
curl -s http://127.0.0.1/api/stock
```

В XML для `balance_id=1` должно появиться `<quantity>125</quantity>`. На странице выберите пример **ID 1** и покажите новое значение в XML и таблице. Для повторной отправки выбранного запроса нажмите **Выполнить GET**.

Такой показ демонстрирует, что ответ формируется из текущих данных MariaDB. При необходимости верните исходное значение.

```bash
sudo mariadb pharmacy -e "UPDATE stock_balances SET quantity=120 WHERE balance_id=1;"
```

## 15. Где находится реализация

| Файл | Назначение |
|---|---|
| `public/api.php` | Проверяет HTTP-метод и URI-параметры, устанавливает XML-заголовки, обрабатывает ошибки и скачивание файла. |
| `src/stock.php`, функция `stockFilters()` | Проверяет имена параметров, типы, даты и границы диапазонов. |
| `src/stock.php`, функция `readStock()` | Подключается к MariaDB через PDO, формирует WHERE из разрешённых условий и привязывает значения к подготовленному SELECT. |
| `src/stock.php`, функция `stockXml()` | Создаёт XML через DOMDocument и текстовые узлы. Это сохраняет кириллицу и экранирует символы `&`, `<`, `>`. |
| `public/app.js` | Формирует URL из полей формы, выполняет GET, показывает статус, заголовки и исходный XML, затем строит таблицу через DOMParser и `textContent`. |
| `database/schema.sql` | Описывает таблицу, начальные записи и права пользователя чтения. |

SQL-запрос без фильтров выглядит так.

```sql
SELECT balance_id, drug_name, batch_number, expiry_date, quantity,
       storage_temperature
FROM stock_balances
ORDER BY balance_id;
```

При запросе `?balance_id=1` перед `ORDER BY` добавляется `WHERE balance_id = :balance_id`, а число 1 передаётся через `bindValue`, без вставки в SQL-текст. Nginx передаёт строку запроса через `QUERY_STRING` из `/etc/nginx/fastcgi_params`.

[PDO в документации PHP](https://www.php.net/manual/en/book.pdo.php), [DOMDocument](https://www.php.net/manual/en/class.domdocument.php).

## 16. Если не работает

| Симптом | Что проверить |
|---|---|
| Fedora снова запускает установщик после перезагрузки | Извлеките ISO из виртуального оптического привода. |
| Чёрный экран рабочего стола | Проверьте VMSVGA, объём видеопамяти и отключение 3D Acceleration. |
| Не работает `sudo` | У пользователя должны быть административные права. Проверьте `groups` — обычно в списке есть `wheel`. |
| `dnf` не скачивает пакеты | Верните NAT, проверьте интернет и время в ВМ. После установки можно снова включить мост. |
| `dnf` не скачивает `openh264` | Для текущей установки Git повторите `sudo dnf --disable-repo=fedora-cisco-openh264 install -y git`. |
| `git clone` сообщает `destination path ... already exists` | Если это уже клонированный проект с каталогом `.git`, используйте `git -C "$HOME/pharmacy-lab" pull --ff-only`. Существующие файлы сохраняйте. |
| `git clone` запрашивает вход в GitHub или сообщает `Repository not found` | Проверьте точный HTTPS-адрес из пункта 7 и наличие временного публичного репозитория на GitHub. После его удаления используйте уже скачанную локальную копию. |
| После `git pull` на сайте старый код | Обновился каталог `~/pharmacy-lab`. Выполните `sudo bash "$HOME/pharmacy-lab/scripts/apply.sh"` из пункта 9, затем обновите страницу через Ctrl+F5. |
| API возвращает HTTP 400 с XML | Проверьте имена и значения URI-параметров по пункту 12.4. Это ошибка запроса, а не подключения к БД. |
| На Windows не открывается даже стандартная страница Nginx | Проверьте IP, мост и сервис `http` в активной зоне firewalld. |
| Открывается старая страница Nginx | Проверьте, что установлен `/etc/nginx/nginx.conf` из проекта, `nginx -t` успешен, выполнен restart. |
| HTTP 502 Bad Gateway | Проверьте `php-fpm`, наличие `/run/php-fpm/pharmacy.sock` и соответствие пути в конфигурации Nginx. |
| API возвращает HTTP 503 с XML | Проверьте MariaDB, пользователя `pharmacy_reader`, пароль, расширение `pdo_mysql` и SELinux boolean. |
| HTTP 403 для файлов сайта | Проверьте права каталогов и SELinux-контекст `/var/www/pharmacy`. |
| HTTP 404 для API | Используйте точный путь `/api/stock` без конечного `/`. |
| В UI пусто или отображается ошибка | Откройте API напрямую и посмотрите HTTP-код. Через F12 → Network проверьте GET `/api/stock`. |
| В браузере `file://` страница не загружает данные | Откройте UI через Nginx по `http://IP-Fedora/`. |
| Кириллица отображается неверно | Проверьте `utf8mb4` в базе, DSN `charset=utf8mb4` и XML-заголовок UTF-8. |

Команды для диагностики внутри Fedora.

```bash
sudo systemctl status nginx php-fpm mariadb --no-pager
sudo nginx -t
sudo php-fpm -t
sudo ss -ltnp | grep -E ':80 |:3306 '
sudo ls -lZ /run/php-fpm/pharmacy.sock
sudo journalctl -u nginx -u php-fpm -u mariadb -n 60 --no-pager
sudo tail -n 40 /var/log/nginx/error.log
sudo tail -n 40 /var/log/php-fpm/error.log
sudo ausearch -m AVC -ts recent
getsebool httpd_can_network_connect_db
```

Если PHP сообщает `could not find driver`, проверьте пакет `php-mysqlnd`, затем перезапустите PHP-FPM. Если в журнале SELinux есть запрет доступа, сверяйте путь и контекст с разделом 10.

## 17. Порядок демонстрации преподавателю

1. Откройте `http://127.0.0.1:8082/` и выберите **Все записи**. Покажите GET-адрес, HTTP 200, `Content-Type: application/xml`, XML и три строки таблицы.
2. Нажмите **ID 1**, затем **ID 2**. Покажите, как изменяются параметр адреса, XML и единственная строка таблицы.
3. Выберите **Два фильтра** и покажите одновременное применение условий по количеству и дате.
4. Введите собственный фильтр, например название «препарат», и нажмите **Выполнить GET**.
5. Покажите **Нет совпадений** и **Ошибка 400**: пустой успешный результат и ошибка запроса имеют разные HTTP-статусы и XML.
6. Выберите **Экспорт XML**, раскройте **Заголовки ответа**, покажите `Content-Disposition`, нажмите **Скачать XML** и откройте файл с одной выбранной записью.
7. По дополнительным вопросам покажите Fedora, службы и структуру таблицы; изменение данных в MariaDB описано в пункте 14, проверка POST с ответом 405 — в пункте 12.3.

Для отчёта пригодятся скриншоты ВМ, работающих служб, структуры таблицы, GET-ответа и простой страницы. В тексте укажите выбранные типы данных, структуру XML и маршрут API.

## 18. Что проверено при подготовке

Проверены PHP-синтаксис и работа обработчика с отдельной тестовой MariaDB 11.8.8 под Windows на PHP 8.4.28. Проверены GET с тремя строками, все шесть полей, UTF-8, ведущие нули номера партии, нулевое количество, экранирование XML, пустая таблица, скачивание файла, HEAD, ответ 405 на POST, ответ 503 при недоступной БД и ограничение пользователя правом SELECT. UI проверен в браузере, включая обновление таблицы.

21 ноября 2026 года дополнительно проверена работа в вашей установленной **Fedora 45 VM**: PHP 8.5.13, Nginx 1.30.6 и MariaDB. Подключение выполнено по SSH с Windows через NAT: `127.0.0.1:2222` → порт 22 Fedora. Сайт доступен с Windows по `http://127.0.0.1:8082/`.

После установки обновления выполнены HTTP-проверки через рабочий Nginx: восемь фильтров и их сочетания, границы диапазонов, кириллица, буквальные `%` и `_`, попытки SQL-инъекции, ошибочные даты и числа, некорректный UTF-8, массивы вместо значений, HTTP 400, HEAD, POST и скачивание отфильтрованного XML. Все шесть групп проверок из `tests/test_api.py` прошли. Отдельно проверены GET и скачивание с Windows через порт 8082. Службы Nginx, PHP-FPM и MariaDB активны.

Обновлённый веб-интерфейс проверен в браузере: все готовые примеры, ручной ввод названия на кириллице, различие редактируемого URL и выполненного запроса, показ исходного XML при HTTP 400 и ошибочной дате, совпадение строк таблицы с XML. Скачанный через браузер файл содержал только выбранную запись ID 2.
