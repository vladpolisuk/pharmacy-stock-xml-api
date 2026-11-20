# Остатки на складе лекарств — XML API

Лабораторная работа, вариант 21: **Fedora + Nginx + MariaDB**, формат ответа — **XML**. API читает остатки из базы данных. Простая веб-страница показывает таблицу и позволяет открыть или скачать XML.

## Запросы

| Метод и путь | Результат |
|---|---|
| `GET /` | Страница с таблицей остатков |
| `GET /api/stock` | Остатки из MariaDB в формате XML |
| `GET /api/stock?download=1` | Скачивание файла `medicine-stock.xml` |
| `HEAD /api/stock` | Заголовки ответа без тела |

API возвращает `Content-Type: application/xml; charset=UTF-8`. Другие методы получают HTTP 405 и XML с ошибкой. Если база недоступна, возвращается HTTP 503 и XML с ошибкой.

## Поля

| Поле XML и БД | Значение |
|---|---|
| `balance_id` | Идентификатор остатка |
| `drug_name` | Название препарата |
| `batch_number` | Номер партии |
| `expiry_date` | Срок годности |
| `quantity` | Количество единиц |
| `storage_temperature` | Температура хранения |

Номера партий хранятся как текст, чтобы сохранять ведущие нули. Температура задаётся текстовым диапазоном. В базе три вымышленные учебные записи; пример ответа находится в [example.xml](example.xml).

## Файлы проекта

- `public/api.php` — обработчик API.
- `src/stock.php` — чтение MariaDB через PDO и формирование XML через DOMDocument.
- `public/index.html`, `public/app.js`, `public/style.css` — простой интерфейс, который получает данные из API.
- `database/schema.sql` — база, таблица, учебные записи и пользователь с правом SELECT.
- `config/` — учебные настройки подключения, Nginx, PHP-FPM и MariaDB.
- [GUIDE_RU.md](GUIDE_RU.md) — инструкция от установки Fedora VM до проверки API с Windows.

## Развёртывание

Требуются Nginx, MariaDB и PHP-FPM с расширениями PDO MySQL и DOM/XML. Веб-корень — `/var/www/pharmacy/public`, конфигурация подключения — `/etc/pharmacy-lab/config.php`. Другой путь к конфигурации можно задать переменной окружения `PHARMACY_CONFIG`.

Для получения файлов на Fedora установите Git и клонируйте репозиторий в каталог, который используется в инструкции:

```bash
sudo dnf install -y git
git clone https://github.com/vladpolisuk/pharmacy-stock-xml-api.git "$HOME/pharmacy-lab"
```

Репозиторий приватный: при клонировании нужна авторизация GitHub. Альтернатива — скачать ZIP через GitHub на Windows и перенести его в VM. После распаковки назовите каталог проекта `pharmacy-lab`.

Далее выполните разделы 8–13 [инструкции](GUIDE_RU.md). Если серверные пакеты и сеть ещё не настроены, начните с раздела 5. Раздел 7 о переносе исходного архива можно пропустить, если проект уже клонирован или распакован.

Пароль в `config/config.php`, SQL и инструкции — общий учебный пример. Для другого стенда задайте собственный пароль пользователя БД и укажите его в `/etc/pharmacy-lab/config.php`. Рабочие настройки и пароли храните вне репозитория.

## Проверка

После настройки сервера внутри Fedora:

```bash
curl -i http://localhost/api/stock
curl -o medicine-stock.xml 'http://localhost/api/stock?download=1'
curl -i -X POST http://localhost/api/stock
```

Ожидаются XML с тремя записями, скачиваемый XML и HTTP 405 для POST. Для проверки с Windows откройте `http://IP-Fedora/` и `http://IP-Fedora/api/stock`.
