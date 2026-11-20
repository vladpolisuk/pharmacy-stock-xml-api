const fields = [
  'balance_id', 'drug_name', 'batch_number',
  'expiry_date', 'quantity', 'storage_temperature',
];
const examples = {
  all: {},
  first: { balance_id: '1' },
  second: { balance_id: '2' },
  combined: { min_quantity: '1', expiry_before: '2027-12-31' },
  empty: { balance_id: '4294967295' },
  invalid: { min_quantity: '-1' },
  export: { balance_id: '2', download: '1' },
};

const form = document.querySelector('#filters');
const statusLine = document.querySelector('#status');
const tableBody = document.querySelector('#stock');
const tableMessage = document.querySelector('#table-message');
const count = document.querySelector('#record-count');
const httpStatus = document.querySelector('#http-status');
const responsePanel = document.querySelector('#response-panel');
const responseBody = document.querySelector('#response-body');
const responseHeaders = document.querySelector('#response-headers');
const exampleButtons = [...document.querySelectorAll('[data-example]')];
const actionButtons = [document.querySelector('#send'), document.querySelector('#clear'), ...exampleButtons];
let lastRequestURL = '';
let activeRequest = 0;
let controller;

function requestURL() {
  const url = new URL('/api/stock', window.location.origin);
  for (const [name, value] of new FormData(form)) {
    if (value.trim() !== '') url.searchParams.set(name, value.trim());
  }
  return url;
}

function updatePreview() {
  const url = requestURL();
  document.querySelector('#request-url').textContent = url.href;
  document.querySelector('#open-xml').href = url.href;
  const downloadURL = new URL(url);
  downloadURL.searchParams.set('download', '1');
  document.querySelector('#download-xml').href = downloadURL.href;
  document.querySelector('#request-hint').textContent = lastRequestURL && lastRequestURL !== url.href
    ? 'Параметры изменены. Выполните GET, чтобы получить ответ для нового запроса.'
    : 'Пустые поля пропускаются, заполненные условия применяются одновременно.';
}

function setExample(name) {
  for (const input of form.querySelectorAll('input')) {
    if (input.type === 'checkbox') input.checked = false;
    else input.value = '';
  }
  for (const [key, value] of Object.entries(examples[name])) {
    const input = form.elements.namedItem(key);
    if (input.type === 'checkbox') input.checked = value === '1';
    else input.value = value;
  }
  for (const button of exampleButtons) {
    button.setAttribute('aria-pressed', String(button.dataset.example === name));
  }
  updatePreview();
}

function showRecords(xml) {
  if (xml.documentElement.tagName !== 'stock_balances') {
    throw new Error('Ответ API имеет неожиданный формат. Исходный ответ показан выше.');
  }
  const records = [...xml.documentElement.children];
  const fragment = document.createDocumentFragment();
  for (const record of records) {
    const row = document.createElement('tr');
    for (const field of fields) {
      const cell = document.createElement('td');
      cell.textContent = record.querySelector(field)?.textContent ?? '';
      row.appendChild(cell);
    }
    fragment.appendChild(row);
  }
  tableBody.replaceChildren(fragment);
  count.textContent = `Записей: ${records.length}`;
  tableMessage.textContent = records.length
    ? 'Значения взяты из XML-ответа выше. Отображаются все шесть полей.'
    : 'Совпадений нет. Сервер вернул успешный ответ с пустым списком.';
  return records.length;
}

async function runRequest() {
  controller?.abort();
  const requestController = new AbortController();
  controller = requestController;
  const requestId = ++activeRequest;
  const url = requestURL();
  lastRequestURL = url.href;
  updatePreview();
  for (const button of actionButtons) button.disabled = true;
  responsePanel.setAttribute('aria-busy', 'true');
  httpStatus.className = 'http-status';
  httpStatus.textContent = 'Загрузка…';
  document.querySelector('#response-url').textContent = url.href;
  statusLine.textContent = 'Выполняется GET-запрос к серверу…';
  responseBody.textContent = 'Ожидание ответа…';
  responseHeaders.textContent = '—';
  tableBody.replaceChildren();
  count.textContent = '—';
  tableMessage.textContent = 'Ожидание XML-ответа.';
  const timeout = setTimeout(() => requestController.abort(), 15000);

  try {
    const response = await fetch(url.href, {
      method: 'GET',
      headers: { Accept: 'application/xml' },
      cache: 'no-store',
      signal: requestController.signal,
    });
    const text = await response.text();
    if (requestId !== activeRequest) return;
    httpStatus.textContent = `HTTP ${response.status}${response.statusText ? ` ${response.statusText}` : ''}`;
    httpStatus.classList.add(response.ok ? 'success' : 'failure');
    responseHeaders.textContent = [...response.headers]
      .map(([name, value]) => `${name}: ${value}`).join('\n');
    responseBody.textContent = text;

    const xml = new DOMParser().parseFromString(text, 'application/xml');
    if (xml.querySelector('parsererror')) {
      throw new Error('Сервер вернул некорректный XML. Исходный ответ показан выше.');
    }
    if (!response.ok) {
      throw new Error(xml.querySelector('error > message')?.textContent || `Ошибка HTTP ${response.status}.`);
    }
    const total = showRecords(xml);
    statusLine.textContent = `Ответ получен. HTTP ${response.status}, записей: ${total}.`;
  } catch (error) {
    if (requestId !== activeRequest) return;
    if (error.name === 'AbortError') {
      httpStatus.textContent = 'Нет ответа';
      httpStatus.classList.add('failure');
      responseBody.textContent = 'Ответ не получен за 15 секунд.';
      statusLine.textContent = 'Время ожидания истекло. Проверьте доступность API и повторите запрос.';
    } else {
      statusLine.textContent = error.message || 'Не удалось получить ответ сервера.';
      if (responseBody.textContent === 'Ожидание ответа…') {
        httpStatus.textContent = 'Нет ответа';
        httpStatus.classList.add('failure');
        responseBody.textContent = 'Тело ответа не получено.';
      }
    }
    count.textContent = 'Нет данных';
    tableMessage.textContent = 'Таблица не построена. Проверьте статус и ответ сервера выше.';
  } finally {
    clearTimeout(timeout);
    if (requestId === activeRequest) {
      for (const button of actionButtons) button.disabled = false;
      responsePanel.setAttribute('aria-busy', 'false');
      updatePreview();
    }
  }
}

form.addEventListener('submit', (event) => {
  event.preventDefault();
  runRequest();
});
form.addEventListener('input', () => {
  for (const button of exampleButtons) button.setAttribute('aria-pressed', 'false');
  updatePreview();
});
document.querySelector('#clear').addEventListener('click', () => {
  setExample('all');
  runRequest();
});
for (const button of exampleButtons) {
  button.addEventListener('click', () => {
    setExample(button.dataset.example);
    runRequest();
  });
}
updatePreview();
runRequest();
