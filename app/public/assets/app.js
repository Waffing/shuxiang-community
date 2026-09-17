'use strict';

const API_URL = '/api.php';
const AGREEMENT_KEY = 'shuxiang.agreement.v1';
const IS_ANDROID_APP = navigator.userAgent.includes('ResourceForumApp/');
if (IS_ANDROID_APP) document.documentElement.classList.add('android-webview');
const state = {
  me: null,
  page: 1,
  totalPages: 1,
  rankings: { weekly: [], latest: [] },
  currentResource: null,
  searchController: null,
  adminQueue: 'software',
  adminPage: 1,
  myPage: 1,
  favoritePage: 1,
  editId: null,
  drawer: null,
  drawerTrigger: null,
  inertElements: []
};

const platforms = [
  { key: 'windows', label: 'Windows', color: '#2974e8', bg: '#e9f2ff' },
  { key: 'macos', label: 'macOS', color: '#202536', bg: '#eef0f4' },
  { key: 'android', label: 'Android', color: '#20a46c', bg: '#e7f8f0' },
  { key: 'ios', label: 'iOS', color: '#725de4', bg: '#f0edff' },
  { key: 'linux', label: 'Linux', color: '#e88b25', bg: '#fff3e3' }
];

const categoryColors = {
  office: ['#6558e8', '#37a8e6'],
  design: ['#ef5da8', '#8a5cf5'],
  developer: ['#15a680', '#55c9a7'],
  media: ['#ef7f45', '#f2ba3e'],
  system: ['#4d75df', '#50b9d0'],
  default: ['#6670e9', '#24b8d6']
};

const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];

function escapeHtml(value) {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

function safeText(value, fallback = '') {
  const text = String(value ?? '').trim();
  return text || fallback;
}

function formatNumber(value) {
  if (value === null || value === undefined || value === '') return '暂无数据';
  const number = Number(value);
  if (!Number.isFinite(number)) return '暂无数据';
  if (number >= 10000) return `${(number / 10000).toFixed(number >= 100000 ? 0 : 1)}万`;
  if (number >= 1000) return `${(number / 1000).toFixed(1)}k`;
  return String(number);
}

function formatBytes(value) {
  const bytes = Number(value || 0);
  if (!Number.isFinite(bytes) || bytes <= 0) return '暂无数据';
  const units = ['B', 'KB', 'MB', 'GB'];
  const unit = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
  return `${(bytes / (1024 ** unit)).toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
}

function formatDate(value) {
  if (!value) return '待更新';
  const date = new Date(String(value).replace(' ', 'T'));
  if (Number.isNaN(date.getTime())) return safeText(value);
  return new Intl.DateTimeFormat('zh-CN', { year: 'numeric', month: '2-digit', day: '2-digit' }).format(date);
}

function truncate(value, length = 74) {
  const text = safeText(value);
  return text.length > length ? `${text.slice(0, length)}…` : text;
}

function normalizeEnvelope(payload) {
  if (!payload || typeof payload !== 'object') return payload;
  if (payload.success === false) throw new Error(apiErrorMessage(payload));
  return Object.prototype.hasOwnProperty.call(payload, 'data') ? payload.data : payload;
}

function apiErrorMessage(payload, status = 0) {
  const messages = {
    authentication_required: '请先登录后再操作。', invalid_token: '登录已过期，请重新登录。',
    invalid_credentials: '用户名或密码不正确，请重试。', invalid_username: '用户名须为 3–24 个汉字、字母、数字、下划线或连字符。',
    invalid_password: '请使用 10–72 位新密码；含中文时请缩短长度，修改时请勿与当前密码相同。', username_taken: '这个用户名已被使用，请换一个。',
    admin_required: '此操作需要管理员权限。', forbidden: '你没有操作这条资源的权限。',
    rate_limit_exceeded: '操作较频繁，请稍后再试。', daily_download_limit: '今日下载额度已用完，请明天再来。',
    resource_locked: '请先满足回复、积分或会员条件再下载。', not_found: '资源不存在、尚未公开或已下架。',
    invalid_origin: '请求来源验证失败，请从本站刷新页面后重试。', internal_server_error: '服务暂时繁忙，请稍后重试。',
    invalid_content: '回复内容须为 2–500 个字符。', invalid_reason: '请填写 3–500 字的处理原因。',
    invalid_software: '请检查软件名称、版本、简介和详细介绍。', invalid_platform: '请至少选择一个支持的平台。',
    invalid_changelog: '更新日志格式不正确，请按每行一项填写。', invalid_download_sources: '请填写至少一个有效下载源。',
    invalid_download_source: '下载来源无效，请检查名称和 HTTP(S) 地址。', invalid_status: '资源状态已变化，请刷新后重试。',
    invalid_upload: '文件上传未完成，请重新选择。', invalid_file_size: '图标文件过大，请压缩后重试。',
    invalid_image_dimensions: '图标尺寸应为 32–8000 像素，且总像素不超过 1600 万。', unsupported_image: '请选择有效的 PNG、JPEG 或 WebP 图标。',
    invalid_dmca_request: '请完整填写权利人、邮箱、资源链接和权利说明。', invalid_parameter: '提交内容不完整或格式有误，请检查后重试。'
  };
  if (messages[payload?.error]) return messages[payload.error];
  if (payload?.message && /[\u3400-\u9fff]/.test(payload.message)) return payload.message;
  if (status === 401) return messages.authentication_required;
  if (status === 403) return messages.forbidden;
  if (status === 429) return messages.rate_limit_exceeded;
  return status >= 500 ? messages.internal_server_error : '请求未完成，请检查输入后重试。';
}

async function api(action, { method = 'GET', params = {}, signal } = {}) {
  const url = new URL(API_URL, location.origin);
  url.searchParams.set('action', action);
  const options = { method, credentials: 'same-origin', headers: { Accept: 'application/json' }, signal };
  if (method === 'GET') {
    Object.entries(params).forEach(([key, value]) => {
      if (value !== '' && value !== null && value !== undefined) {
        if (Array.isArray(value)) value.forEach((item) => url.searchParams.append(`${key}[]`, item));
        else url.searchParams.set(key, String(value));
      }
    });
  } else {
    options.headers['Content-Type'] = 'application/json';
    options.body = JSON.stringify(params);
  }
  let response;
  try { response = await fetch(url, options); }
  catch (error) {
    if (error.name === 'AbortError') throw error;
    throw new Error('网络连接失败，请检查网络后重试。');
  }
  let payload;
  try {
    payload = await response.json();
  } catch {
    throw new Error('服务器返回了不可解析的数据');
  }
  if (!response.ok) {
    const message = action === 'change_password' && payload.error === 'invalid_credentials' ? '当前密码不正确，请重新输入。' : apiErrorMessage(payload, response.status);
    const error = new Error(message);
    error.status = response.status;
    throw error;
  }
  return normalizeEnvelope(payload);
}

function platformIcon(key) {
  const icons = {
    windows: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.7 4.5 10.8 3v8.2H2.7V4.5Zm9.2-1.7L21.3 1v10.2h-9.4V2.8ZM2.7 12.3h8.1v8.2L2.7 19v-6.7Zm9.2 0h9.4v10.2l-9.4-1.7v-8.5Z"/></svg>',
    macos: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17.1 12.8c0-3 2.5-4.5 2.6-4.6a5.6 5.6 0 0 0-4.4-2.4c-1.9-.2-3.7 1.1-4.6 1.1-1 0-2.4-1.1-4-1.1a5.9 5.9 0 0 0-5 3c-2.1 3.7-.5 9.2 1.5 12.2 1 1.5 2.2 3.1 3.8 3 1.5-.1 2.1-1 3.9-1s2.3 1 3.9 1c1.6 0 2.7-1.5 3.7-3 1.2-1.7 1.7-3.4 1.7-3.5-.1 0-3.1-1.2-3.1-4.7ZM14.1 3.8A5.2 5.2 0 0 0 15.3 0a5.3 5.3 0 0 0-3.5 1.8 4.9 4.9 0 0 0-1.3 3.6c1.3.1 2.7-.6 3.6-1.6Z" transform="scale(.78) translate(2.5 2)"/></svg>',
    android: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 5-1.4-2a.6.6 0 1 1 1-.7L8.2 4.5A9 9 0 0 1 12 3.7c1.4 0 2.7.3 3.8.8l1.6-2.2a.6.6 0 1 1 1 .7L17 5a7.1 7.1 0 0 1 2.9 5.3H4.1A7.1 7.1 0 0 1 7 5Zm1.5 3.1a.9.9 0 1 0 0-1.8.9.9 0 0 0 0 1.8Zm7 0a.9.9 0 1 0 0-1.8.9.9 0 0 0 0 1.8ZM4 11.5h16v8a1.5 1.5 0 0 1-1.5 1.5h-1v2.1a.9.9 0 0 1-1.8 0V21H8.3v2.1a.9.9 0 0 1-1.8 0V21h-1A1.5 1.5 0 0 1 4 19.5v-8Z"/></svg>',
    ios: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16.3 12.7c0-2.4 2-3.6 2.1-3.6a4.4 4.4 0 0 0-3.5-1.9c-1.5-.2-2.9.9-3.7.9s-1.9-.9-3.2-.8a4.7 4.7 0 0 0-4 2.4c-1.7 3-.4 7.3 1.2 9.7.8 1.2 1.8 2.4 3 2.4 1.2 0 1.7-.8 3.1-.8 1.5 0 1.9.8 3.1.8 1.3 0 2.2-1.2 3-2.4.9-1.3 1.3-2.7 1.3-2.8-.1 0-2.4-1-2.4-3.9ZM14 5.6a4.2 4.2 0 0 0 1-3 4.2 4.2 0 0 0-2.8 1.4 3.9 3.9 0 0 0-1 2.9c1 .1 2.1-.5 2.8-1.3Z"/></svg>',
    linux: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17.4 13.1c.2-1 .1-2.2-.2-3.4-.7-2.6-2.3-4.4-3.2-5.6-.6-.9-.8-2.5-2-2.5s-1.5 1.6-2.1 2.5C9 5.3 7.4 7.1 6.8 9.7c-.3 1.2-.4 2.4-.2 3.4-1.6 1.6-2.6 3.9-2.2 5 .4 1 1.8.8 3.4-.1.4 2.2 2 4.4 4.2 4.4s3.8-2.2 4.2-4.4c1.6.9 3 1.1 3.4.1.4-1.1-.6-3.4-2.2-5ZM9.6 9a1.1 1.1 0 1 1 0-2.2 1.1 1.1 0 0 1 0 2.2Zm4.8 0a1.1 1.1 0 1 1 0-2.2 1.1 1.1 0 0 1 0 2.2ZM12 10c1.4 0 2.4.7 2.4 1.4 0 .7-1 1.3-2.4 1.3s-2.4-.6-2.4-1.3c0-.7 1-1.4 2.4-1.4Z"/></svg>'
  };
  return icons[String(key).toLowerCase()] || icons.windows;
}

function generatedIcon(name, category = 'default') {
  const letter = Array.from(safeText(name, 'S'))[0].toUpperCase();
  const categoryValue = String(category).toLowerCase();
  const colors = /^#[0-9a-f]{6}$/i.test(categoryValue) ? [categoryValue, '#20b8d3'] : (categoryColors[categoryValue] || categoryColors.default);
  const safeLetter = escapeHtml(letter);
  const gradientId = `g-${[...`${name}:${category}`].reduce((hash, char) => ((hash * 31) + char.codePointAt(0)) >>> 0, 7)}`;
  return `<svg viewBox="0 0 64 64" role="img" aria-label="${escapeHtml(name)} 默认图标"><defs><linearGradient id="${gradientId}" x1="5" y1="3" x2="58" y2="61"><stop stop-color="${colors[0]}"/><stop offset="1" stop-color="${colors[1]}"/></linearGradient></defs><rect width="64" height="64" rx="17" fill="url(#${gradientId})"/><circle cx="51" cy="13" r="9" fill="#fff" opacity=".13"/><path d="M13 51c12-12 26-10 38-26v26Z" fill="#fff" opacity=".09"/><text x="32" y="40" fill="#fff" font-family="system-ui,sans-serif" font-size="28" font-weight="800" text-anchor="middle">${safeLetter}</text></svg>`;
}

function softwareIcon(item) {
  const iconPath = safeText(item.icon_path);
  if (/^\/uploads\/icons\/[a-zA-Z0-9.-]+\.webp$/.test(iconPath)) {
    return `<img src="${escapeHtml(iconPath)}" alt="${escapeHtml(item.name)} 图标" width="64" height="64" loading="lazy" decoding="async">`;
  }
  return generatedIcon(item.name, item.category_color || item.category_key || item.category);
}

function arrayPlatforms(item) {
  const values = Array.isArray(item.platforms) ? item.platforms : String(item.platform || '').split(',');
  return values.map((entry) => typeof entry === 'object' ? (entry.key || entry.name) : entry).filter(Boolean);
}

function normalizeItems(data) {
  if (Array.isArray(data)) return data;
  return Array.isArray(data?.items) ? data.items : [];
}

function resourceUrl(item) {
  return item.slug && (!item.status || item.status === 'published')
    ? `/software/${encodeURIComponent(item.slug)}` : `#/detail/${encodeURIComponent(item.id)}`;
}

function resourceCard(item) {
  const platformTags = arrayPlatforms(item).slice(0, 2).map((platform) => `<span class="tag">${escapeHtml(platform)}</span>`).join('');
  const vip = Number(item.is_vip) || item.access === 'vip' ? '<span class="tag tag--vip">VIP</span>' : '';
  const trust = item.trust_status === 'verified' ? '<span class="tag tag--trust">验证记录完整</span>' : '';
  return `<a class="resource-card" href="${resourceUrl(item)}">
    <span class="software-icon">${softwareIcon(item)}</span>
    <span class="resource-card__body">
      <span class="resource-card__title"><h3>${escapeHtml(safeText(item.name, '未命名资源'))}</h3>${vip}</span>
      <span class="resource-card__summary">${escapeHtml(truncate(item.summary || item.description, 62) || '查看版本信息、更新记录与下载来源')}</span>
      <span class="resource-card__meta">${platformTags}<span class="tag">${escapeHtml(safeText(item.version, '版本待定'))}</span>${trust}<span>${formatDate(item.updated_at)}</span></span>
    </span>
    <span class="resource-card__stats"><span><svg><use href="#i-download"/></svg>${formatNumber(item.downloads_count ?? item.download_count ?? item.downloads)}</span><span><svg><use href="#i-message"/></svg>${formatNumber(item.replies_count ?? item.reply_count)}</span></span>
  </a>`;
}

function renderEmpty(target, title, description) {
  target.innerHTML = `<div class="empty-state"><div class="empty-illustration" aria-hidden="true"><svg viewBox="0 0 180 130"><path d="M31 42h118v73H31z" fill="#eef0ff"/><path d="M31 42 48 20h84l17 22" fill="#fff" stroke="#adb4e9" stroke-width="4"/><path d="M72 77h36" stroke="#6d70d8" stroke-width="5" stroke-linecap="round"/><circle cx="67" cy="62" r="4" fill="#9199dc"/><circle cx="113" cy="62" r="4" fill="#9199dc"/></svg></div><h3>${escapeHtml(title)}</h3><p>${escapeHtml(description)}</p></div>`;
}

function renderPlatformNavigation() {
  $('#platform-grid').innerHTML = platforms.map((platform) => `<a class="platform-card" href="#/search?platform=${platform.key}" style="--platform-color:${platform.color};--platform-bg:${platform.bg}"><span class="platform-card__icon">${platformIcon(platform.key)}</span><span><strong>${platform.label}</strong><small>浏览资源</small></span></a>`).join('');
  $('#platform-filters').innerHTML = platforms.map((platform) => `<label><input type="checkbox" name="platform" value="${platform.key}"><span>${platform.label}</span></label>`).join('');
}

async function loadHome() {
  const latest = $('#latest-list');
  const weekly = $('#weekly-ranking');
  latest.innerHTML = '<div class="detail-skeleton" style="height:260px"></div>';
  weekly.innerHTML = '<li><a><strong>正在加载榜单</strong><small>请稍候</small></a></li>';
  const [listResult, rankingResult] = await Promise.allSettled([
    api('list', { params: { sort: 'updated', page: 1, limit: 6 } }),
    api('rankings')
  ]);
  const items = listResult.status === 'fulfilled' ? normalizeItems(listResult.value) : [];
  if (listResult.status === 'rejected') renderError(latest, listResult.reason.message, 'home');
  else if (items.length) latest.innerHTML = items.map(resourceCard).join('');
  else renderEmpty(latest, '暂无最新资源', '资源发布后会显示在这里。');

  if (rankingResult.status === 'fulfilled') {
    state.rankings = {
      weekly: normalizeItems(rankingResult.value.weekly),
      latest: normalizeItems(rankingResult.value.latest)
    };
  }
  const rankItems = state.rankings.weekly.slice(0, 6);
  if (rankingResult.status === 'rejected') weekly.innerHTML = '<li><button class="text-button" data-retry="home">榜单加载失败，点击重试</button></li>';
  else weekly.innerHTML = rankItems.length ? rankItems.map((item) => `<li><a href="${resourceUrl(item)}"><strong>${escapeHtml(item.name)}</strong><small>${formatNumber(item.downloads ?? item.downloads_count ?? item.download_count)} 本周下载</small></a></li>`).join('') : '<li><a href="#/rankings"><strong>榜单等待更新</strong><small>暂无数据</small></a></li>';
}

function filtersFromUrl() {
  const [, queryString = ''] = location.hash.split('?');
  const params = new URLSearchParams(queryString);
  return {
    q: params.get('q') || '',
    platform: params.getAll('platform').length ? params.getAll('platform') : (!location.hash && document.body.dataset.platform ? [document.body.dataset.platform] : []),
    category: params.get('category') || (!location.hash ? (document.body.dataset.category || '') : ''),
    updated: params.get('updated') || '',
    access: params.get('access') || '',
    sort: params.get('sort') || 'updated',
    page: /^\d+$/.test(params.get('page') || '1') ? Math.max(1, Number(params.get('page') || 1)) : 1
  };
}

function applyFiltersToForm(filters) {
  $('#library-query').value = filters.q;
  $$('input[name="platform"]', $('#filter-form')).forEach((input) => { input.checked = filters.platform.includes(input.value); });
  const updated = $(`input[name="updated"][value="${CSS.escape(filters.updated)}"]`) || $('input[name="updated"][value=""]');
  const access = $(`input[name="access"][value="${CSS.escape(filters.access)}"]`) || $('input[name="access"][value=""]');
  updated.checked = true;
  access.checked = true;
  $('#sort-select').value = filters.sort;
}

function filterUrl(values) {
  const params = new URLSearchParams();
  if (values.q) params.set('q', values.q);
  (values.platform || []).forEach((value) => params.append('platform', value));
  if (values.category) params.set('category', values.category);
  if (values.updated) params.set('updated', values.updated);
  if (values.access) params.set('access', values.access);
  if (values.sort && values.sort !== 'updated') params.set('sort', values.sort);
  if (Number(values.page) > 1) params.set('page', values.page);
  const query = params.toString();
  return `#/search${query ? `?${query}` : ''}`;
}

async function loadSearch() {
  const filters = filtersFromUrl();
  state.page = filters.page;
  applyFiltersToForm(filters);
  if (state.searchController) state.searchController.abort();
  state.searchController = new AbortController();
  const target = $('#search-results');
  target.innerHTML = '<div class="detail-skeleton" style="height:330px"></div>';
  $('#results-title').textContent = '正在搜索资源…';
  try {
    const platform = filters.platform.length > 1 ? filters.platform : (filters.platform[0] || '');
    const data = await api(filters.q ? 'search' : 'list', {
      params: { ...filters, platform, limit: 12 },
      signal: state.searchController.signal
    });
    const items = normalizeItems(data);
    const pagination = data.pagination || data;
    state.totalPages = Math.max(1, Number(pagination.total_pages || 1));
    state.page = Number(pagination.page || filters.page);
    const total = pagination.total;
    $('#results-title').textContent = `找到 ${formatNumber(total)} 个资源`;
    if (items.length) target.innerHTML = items.map(resourceCard).join('');
    else renderEmpty(target, '没有匹配的资源', '试试减少筛选条件或更换关键词。');
    renderPagination(state.page, state.totalPages);
  } catch (error) {
    if (error.name === 'AbortError') return;
    $('#results-title').textContent = '资源加载失败';
    renderError(target, error.message, 'search');
    $('#pagination').innerHTML = '';
  }
}

function renderPagination(page, pages) {
  $('#pagination').innerHTML = paginationMarkup(page, pages, 'page');
}

function paginationMarkup(page, pages, attribute) {
  if (pages <= 1) return '';
  const numbers = [...new Set([1, page - 1, page, page + 1, pages].filter((value) => value >= 1 && value <= pages))];
  return `<button data-${attribute}="${page - 1}" ${page === 1 ? 'disabled' : ''} aria-label="上一页">‹</button>${numbers.map((value, index) => `${index && value - numbers[index - 1] > 1 ? '<span aria-hidden="true">…</span>' : ''}<button data-${attribute}="${value}" class="${value === page ? 'is-active' : ''}" ${value === page ? 'aria-current="page"' : ''}>${value}</button>`).join('')}<button data-${attribute}="${page + 1}" ${page >= pages ? 'disabled' : ''} aria-label="下一页">›</button>`;
}

function renderError(target, message, retry) {
  target.innerHTML = `<div class="inline-error" role="alert"><p>${escapeHtml(message)}</p><button class="button button--ghost" data-retry="${retry}">重新加载</button></div>`;
}

async function loadRankings() {
  const target = $('#leaderboard');
  target.innerHTML = '<div class="detail-skeleton" style="height:420px"></div>';
  try {
    const data = await api('rankings');
    state.rankings = { weekly: normalizeItems(data.weekly), latest: normalizeItems(data.latest) };
    renderLeaderboard($('[data-ranking][aria-selected="true"]').dataset.ranking);
  } catch (error) {
    renderError(target, error.message, 'rankings');
  }
}

function renderLeaderboard(type) {
  const target = $('#leaderboard');
  const items = state.rankings[type] || [];
  if (!items.length) { renderEmpty(target, '榜单等待更新', '产生下载或发布更新后会生成排名。'); return; }
  target.innerHTML = items.map((item, index) => `<a class="leader-row" href="${resourceUrl(item)}"><span class="leader-row__number">${String(index + 1).padStart(2, '0')}</span><span class="software-icon">${softwareIcon(item)}</span><span><h3>${escapeHtml(item.name)}</h3><p>${escapeHtml(truncate(item.summary || item.description, 80) || `${safeText(item.version, '当前版本')} · 社区资源`)}</p></span><span class="leader-row__score"><strong>${type === 'weekly' ? formatNumber(item.downloads ?? item.downloads_count ?? item.download_count) : formatDate(item.updated_at)}</strong>${type === 'weekly' ? '次下载' : '更新时间'}</span></a>`).join('');
}

function sourceMarkup(source, unlocked) {
  if (!unlocked) return '';
  return `<button class="source-button" data-source-id="${escapeHtml(source.id)}" data-source-type="${source.type === 'direct' ? 'direct' : 'external'}"><svg><use href="${source.type === 'direct' ? '#i-download' : '#i-external'}"/></svg><span><strong>${escapeHtml(safeText(source.name || source.label, source.type === 'direct' ? '本站下载' : '外部网盘'))}</strong><small>${source.type === 'direct' ? '短时令牌保护' : '即将离开本站，请核对第三方页面'}</small></span><svg><use href="#i-chevron"/></svg></button>`;
}

async function loadDetail(id) {
  const target = $('#detail-view');
  state.currentResource = null;
  $('#report-software-id').value = '';
  target.innerHTML = '<div class="detail-skeleton"></div>';
  try {
    const data = await api('detail', { params: { id } });
    const item = data.item || data;
    const routeInfo = currentRoute();
    if (item.status === 'published' && item.slug && routeInfo.page === 'detail' && String(routeInfo.id) === String(item.id)) {
      const canonical = new URL(resourceUrl(item), location.origin).href;
      document.body.dataset.resourceId = String(item.id);
      history.replaceState({ resourceId: String(item.id) }, '', canonical);
      document.title = `${safeText(item.name)} · 数享社区`;
      const canonicalLink = $('link[rel="canonical"]');
      if (canonicalLink) canonicalLink.href = canonical;
      const metadata = { 'meta[name="description"]': truncate(item.summary || item.description, 160), 'meta[property="og:title"]': document.title, 'meta[property="og:url"]': canonical, 'meta[property="og:description"]': truncate(item.summary || item.description, 160) };
      Object.entries(metadata).forEach(([selector, value]) => { const meta = $(selector); if (meta) meta.content = value; });
    }
    const downloads = Array.isArray(data.downloads) ? data.downloads : (item.download_sources || item.downloads || []);
    const canUnlock = Boolean(data.can_unlock ?? item.can_unlock ?? item.unlocked ?? !item.reply_required);
    state.currentResource = item;
    $('#report-software-id').value = item.id;
    const platformTags = arrayPlatforms(item).map((value) => `<span class="tag">${escapeHtml(value)}</span>`).join('');
    const hashes = [
      ['MD5', item.md5 || item.file_md5],
      ['SHA256', item.sha256 || item.file_sha256]
    ].filter(([, value]) => value);
    const lockedMarkup = canUnlock ? '' : `<div class="locked-content"><svg><use href="#i-lock"/></svg><h3>下载信息尚未解锁</h3><p>${Number(item.is_vip) ? '需要 VIP 资格。' : ''}${item.unlock_points || item.points_required ? `积分需达到 ${Number(item.unlock_points || item.points_required)}。` : ''}${Number(item.reply_required) ? '需在此资源下发布有意义的回复。' : ''}满足以上条件后刷新访问状态。</p><button class="button button--ghost" data-focus-reply>参与讨论</button></div>`;
    const sourcesMarkup = canUnlock && downloads.length ? downloads.map((source) => sourceMarkup(source, true)).join('') : lockedMarkup;
    const passwordValue = item.extract_password || item.archive_password || item.password;
    const password = canUnlock && passwordValue ? `<div class="password-box"><span><small>解压密码</small><code>${escapeHtml(passwordValue)}</code></span><button data-copy="${escapeHtml(passwordValue)}" aria-label="复制解压密码"><svg><use href="#i-copy"/></svg></button></div>` : '';
    const changelog = Array.isArray(item.changelog) ? item.changelog.map((entry) => typeof entry === 'object' ? `${entry.version ? `${entry.version}：` : ''}${entry.content || entry.text || entry.description || ''}` : entry).filter(Boolean).join('\n') : safeText(item.changelog);
    const trust = item.trust || {};
    const verified = item.trust_status === 'verified' && trust.status === 'verified';
    const published = !item.status || item.status === 'published';
    const replies = Array.isArray(data.replies || item.replies) ? (data.replies || item.replies) : [];
    const discussion = published ? `<section class="detail-card" aria-labelledby="reply-title"><h2 id="reply-title">社区讨论 <small>最近 ${replies.length} 条</small></h2><div class="reply-list">${replies.length ? replies.map((reply) => `<article><strong>${escapeHtml(reply.username)}</strong><time>${formatDate(reply.created_at)}</time><p>${escapeHtml(reply.content)}</p></article>`).join('') : '<p>还没有讨论，分享你的使用体验。</p>'}</div><form class="reply-form" id="reply-form"><input type="hidden" name="software_id" value="${escapeHtml(item.id)}"><label class="sr-only" for="reply-content">回复内容</label><input id="reply-content" name="content" required minlength="2" maxlength="500" placeholder="分享使用体验或提出问题"><button class="button button--primary" type="submit">提交回复</button></form></section>` : '';
    const reviewNote = !published || item.can_edit ? `<div class="resource-review-note"><span class="status-pill">${resourceStatus(item.status, item.moderation_reason)}</span>${item.moderation_reason ? `<p>处理说明：${escapeHtml(item.moderation_reason)}</p>` : ''}${item.can_edit ? `<a class="button button--ghost" href="#/edit/${encodeURIComponent(item.id)}">编辑资源</a>` : ''}</div>` : '';
    const trustRows = [
      ['文件名称', trust.file_name],
      ['文件大小', trust.file_size ? formatBytes(trust.file_size) : null],
      ['数字签名发布者', trust.signature_publisher],
      ['来源域名', trust.source_domain],
      ['最终跳转域名', trust.final_domain],
      ['扫描引擎', trust.scan_engine],
      ['扫描结果', trust.scan_result === 'clean' ? '未发现风险' : null],
      ['扫描时间', trust.scanned_at ? formatDate(trust.scanned_at) : null],
      ['验证人员 / 任务', trust.verifier || trust.verification_task],
      ['验证时间', trust.verified_at ? formatDate(trust.verified_at) : null],
      ['最近下载源检测', trust.source_checked_at ? formatDate(trust.source_checked_at) : null]
    ].filter(([, value]) => value);
    const trustPassport = `<section class="detail-card"><h2>资源信任护照</h2><div class="trust-banner ${verified ? '' : 'trust-banner--unknown'}"><svg><use href="#i-shield"/></svg><span><strong>${verified ? '验证记录完整' : '暂无完整验证记录'}</strong><span>${verified ? '结论来自结构化扫描、来源和验证记录' : '下载前请自行核验来源、签名与文件哈希'}</span></span></div>${trustRows.length ? `<div class="trust-evidence">${trustRows.map(([label, value]) => `<div><span>${escapeHtml(label)}</span><strong>${escapeHtml(value)}</strong></div>`).join('')}</div>` : '<p>当前没有可用于形成信任结论的独立证据。</p>'}</section>`;
    const sourceInspection = item.can_edit && Array.isArray(item.edit_sources) ? `<section class="detail-card"><h2>发布来源核对</h2><p>此信息仅向作者与管理员展示。</p><div class="trust-evidence">${item.edit_sources.map((source) => `<div><span>${escapeHtml(source.label)} · ${Number(source.enabled) ? '启用' : '停用'}</span><strong>${escapeHtml(source.type === 'direct' ? '本站托管文件' : source.url)}</strong></div>`).join('')}</div></section>` : '';
    target.innerHTML = `${reviewNote}<header class="detail-header"><span class="software-icon">${softwareIcon(item)}</span><div class="detail-header__body"><h1 id="detail-title">${escapeHtml(safeText(item.name, '未命名资源'))}</h1><div class="detail-tags">${platformTags}<span class="tag">${escapeHtml(safeText(item.version, '版本待定'))}</span>${Number(item.is_vip) || item.access === 'vip' ? '<span class="tag tag--vip">VIP</span>' : ''}${verified ? '<span class="tag tag--trust">验证记录完整</span>' : ''}</div><p>${escapeHtml(safeText(item.summary || item.description, '查看软件详情、版本与下载来源。'))}</p></div><div class="detail-stats"><span><strong>${formatNumber(item.downloads_count ?? item.download_count ?? (typeof item.downloads === 'number' ? item.downloads : null))}</strong>下载</span><span><strong>${formatNumber(item.views_count ?? item.view_count ?? item.views)}</strong>浏览</span><span><strong>${formatNumber(item.replies_count ?? item.reply_count)}</strong>回复</span></div></header>
      <div class="detail-layout"><div><article class="detail-card"><h2>软件介绍</h2><p>${escapeHtml(safeText(item.description, '发布者暂未填写详细介绍。'))}</p>${changelog ? `<details class="changelog"><summary>查看 ${escapeHtml(safeText(item.version, '当前版本'))} 更新日志</summary><p>${escapeHtml(changelog)}</p></details>` : ''}</article>${trustPassport}${hashes.length ? `<section class="detail-card"><h2>文件校验</h2><div class="hash-list">${hashes.map(([label, value]) => `<div class="hash-row"><span>${label}</span><code>${escapeHtml(value)}</code><button data-copy="${escapeHtml(value)}" aria-label="复制 ${label}"><svg><use href="#i-copy"/></svg></button></div>`).join('')}</div></section>` : ''}</div>
      <aside><section class="detail-card download-panel"><h2>下载资源</h2><div class="source-list">${published ? (sourcesMarkup || '<p>当前没有可用下载源。</p>') : '<p>此资源尚未公开，审核通过后提供下载。</p>'}</div>${published ? password : ''}${published ? `<div class="action-row"><button class="button button--ghost favorite-button" aria-pressed="false" data-favorite-id="${escapeHtml(item.id)}"><svg><use href="#i-heart"/></svg><span>收藏</span></button><button class="button button--ghost" data-modal="download-notice">下载须知</button><button class="button button--danger" data-modal="dead-link">链接失效</button></div>` : ''}</section></aside></div>${sourceInspection}${discussion}`;
    if (state.me && published) await refreshFavoriteButton(item.id);
  } catch (error) {
    target.innerHTML = `<div class="empty-state"><h1>资源读取失败</h1><p>${escapeHtml(error.message)}</p><a class="button button--primary" href="#/search">返回资源库</a></div>`;
  }
}

async function requestDownload(sourceId, button) {
  const original = button.innerHTML;
  button.disabled = true;
  button.textContent = '正在生成下载链接…';
  const downloadWindow = IS_ANDROID_APP ? null : window.open('', '_blank');
  if (downloadWindow) downloadWindow.opener = null;
  try {
    const data = await api('download_token', { method: 'POST', params: { source_id: sourceId } });
    const url = data.url || data.download_url;
    if (!url) throw new Error('下载地址生成失败');
    const parsed = new URL(url, location.origin);
    if (!['http:', 'https:'].includes(parsed.protocol)) throw new Error('下载协议不受支持');
    if (downloadWindow) downloadWindow.location.replace(parsed.href);
    else location.assign(parsed.href);
    toast(button.dataset.sourceType === 'external' ? '即将访问第三方下载页面，请核对来源。' : '下载链接已生成');
  } catch (error) {
    downloadWindow?.close();
    toast(error.message, 'error');
  } finally {
    button.disabled = false;
    button.innerHTML = original;
  }
}

async function refreshFavoriteButton(softwareId) {
  const button = $(`[data-favorite-id="${CSS.escape(String(softwareId))}"]`);
  if (!button || !state.me) return;
  try {
    const data = await api('favorite_status', { params: { software_id: softwareId } });
    button.classList.toggle('is-active', Boolean(data.favorited));
    button.setAttribute('aria-pressed', String(Boolean(data.favorited)));
    $('span', button).textContent = data.favorited ? '已收藏' : '收藏';
  } catch { /* Detail remains usable when favorite status is unavailable. */ }
}

function resourceStatus(status, reason = '') {
  if (status === 'draft' && reason) return '需修改';
  return ({ draft: '待审核', published: '已公开', disabled: '已下架' })[status] || '待审核';
}

function sourceEditorRow(source = {}) {
  const direct = source.type === 'direct';
  return `<div class="source-editor__row" data-edit-source-id="${Number(source.id || 0)}" data-edit-source-type="${direct ? 'direct' : 'external'}"><label><span>来源名称</span><input data-source-label maxlength="50" required value="${escapeHtml(source.label || '')}" placeholder="官方地址 / 百度网盘"></label><label><span>${direct ? '托管文件' : 'HTTP(S) 下载地址'}</span><input data-source-url type="${direct ? 'text' : 'url'}" ${direct ? 'disabled value="已保留现有托管文件"' : `required value="${escapeHtml(source.url || '')}" placeholder="https://"`}></label><button class="icon-button source-editor__remove" type="button" data-remove-source aria-label="删除下载源"><svg><use href="#i-close"/></svg></button></div>`;
}

function initializePublishForm() {
  $('#publish-platforms').innerHTML = platforms.map((platform) => `<label><input type="checkbox" value="${platform.key}"><span>${platformIcon(platform.key)}${platform.label}</span></label>`).join('');
  $('#source-editor').innerHTML = sourceEditorRow();
}

async function loadPublish(id = null) {
  const form = $('#publish-form');
  state.editId = null;
  form.reset();
  initializePublishForm();
  $('#publish-feedback').textContent = '';
  $('#publish-title').textContent = id ? '编辑软件资源' : '发布软件资源';
  $('#publish-description').textContent = '普通用户提交或修改后进入待审核；审核通过后公开。';
  $('button[type="submit"]', form).textContent = id ? '保存修改' : '提交资源';
  form.hidden = !state.me || Boolean(id);
  if (!state.me) { signedOutWorkspace($('#publish-feedback'), '请先登录后发布'); return; }
  if (!id) return;
  $('#publish-feedback').textContent = '正在读取资源';
  try {
    const data = await api('detail', { params: { id } });
    const item = data.item || data;
    if (!item.can_edit) throw new Error('你没有编辑此资源的权限。');
    state.editId = Number(item.id);
    if (item.category && ![...form.elements.category.options].some((option) => option.value === item.category)) {
      form.elements.category.insertAdjacentHTML('beforeend', `<option value="${escapeHtml(item.category)}">${escapeHtml(item.category)}</option>`);
    }
    ['name', 'version', 'category', 'category_color', 'summary', 'description', 'points_required', 'archive_password', 'md5', 'sha256'].forEach((key) => {
      if (form.elements[key]) form.elements[key].value = item[key] ?? '';
    });
    form.elements.changelog.value = Array.isArray(item.changelog) ? item.changelog.join('\n') : safeText(item.changelog);
    form.elements.reply_required.checked = Boolean(Number(item.reply_required));
    form.elements.is_vip.checked = Boolean(Number(item.is_vip));
    const selected = arrayPlatforms(item);
    $$('input', $('#publish-platforms')).forEach((input) => { input.checked = selected.includes(input.value); });
    const sources = (item.edit_sources || []).filter((source) => Number(source.enabled ?? 1));
    $('#source-editor').innerHTML = sources.length ? sources.map(sourceEditorRow).join('') : sourceEditorRow();
    $('#publish-feedback').textContent = item.moderation_reason ? `处理说明：${item.moderation_reason}` : '';
    form.hidden = false;
  } catch (error) { renderError($('#publish-feedback'), error.message, 'publish'); }
}

async function uploadPublishedIcon(softwareId, file) {
  if (!file || file.size === 0) return;
  const body = new FormData();
  body.append('software_id', String(softwareId));
  body.append('icon', file);
  const response = await fetch(`${API_URL}?action=upload_icon`, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' }, body });
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(apiErrorMessage(payload, response.status));
}

async function submitPublish(form) {
  if (!state.me) { profileDialogMarkup(); return; }
  const submit = $('button[type="submit"]', form);
  const data = new FormData(form);
  const selectedPlatforms = $$('input[type="checkbox"]', $('#publish-platforms')).filter((input) => input.checked).map((input) => input.value);
  const downloadSources = $$('.source-editor__row', $('#source-editor')).map((row, index) => ({
    id: Number(row.dataset.editSourceId || 0),
    type: row.dataset.editSourceType,
    label: $('[data-source-label]', row).value.trim(),
    url: row.dataset.editSourceType === 'direct' ? null : $('[data-source-url]', row).value.trim(),
    priority: (index + 1) * 10
  })).filter((source) => source.label && (source.url || source.type === 'direct'));
  if (!selectedPlatforms.length) { toast('请至少选择一个支持平台', 'error'); return; }
  if (!downloadSources.length) { toast('请至少填写一个下载来源', 'error'); return; }
  const payload = {
    name: data.get('name').trim(), version: data.get('version').trim(), category: data.get('category'), category_color: data.get('category_color'),
    summary: data.get('summary').trim(), description: data.get('description').trim(),
    changelog: data.get('changelog').split(/\r?\n/).map((line) => line.trim()).filter(Boolean),
    platforms: selectedPlatforms, reply_required: data.get('reply_required') === 'on', is_vip: data.get('is_vip') === 'on',
    archive_password: data.get('archive_password').trim(), md5: data.get('md5').trim(), sha256: data.get('sha256').trim(),
    points_required: Number(data.get('points_required') || 0), download_sources: downloadSources
  };
  submit.disabled = true;
  submit.textContent = '正在提交…';
  try {
    const published = await api(state.editId ? 'update_resource' : 'publish', { method: 'POST', params: state.editId ? { ...payload, id: state.editId } : payload });
    const icon = data.get('icon');
    let iconWarning = '';
    if (icon?.size) {
      try { await uploadPublishedIcon(published.id, icon); }
      catch { iconWarning = '；图标处理未完成，已使用默认 SVG'; }
    }
    toast(`${published.status === 'published' ? '资源已公开' : '提交成功，等待管理员审核'}${published.points_awarded ? `，获得 ${published.points_awarded} 积分` : ''}${iconWarning}`);
    state.editId = null;
    form.reset();
    initializePublishForm();
    await loadMe();
    location.hash = '#/account';
  } catch (error) {
    toast(error.message, 'error');
  } finally {
    submit.disabled = false;
    submit.textContent = state.editId ? '保存修改' : '提交资源';
  }
}

function signedOutWorkspace(target, title) {
  target.innerHTML = `<div class="empty-state"><div class="empty-illustration" aria-hidden="true"><svg viewBox="0 0 180 130"><circle cx="90" cy="45" r="24" fill="#eef0ff"/><path d="M45 114c3-32 22-49 45-49s42 17 45 49" fill="#eef0ff" stroke="#adb4e9" stroke-width="4"/></svg></div><h2>${escapeHtml(title)}</h2><p>登录后即可使用该功能并同步社区数据。</p><button class="button button--primary" data-open-login>登录或注册</button></div>`;
}

async function loadAccount() {
  const target = $('#account-dashboard');
  if (!state.me) { signedOutWorkspace(target, '登录后查看个人中心'); return; }
  target.innerHTML = '<div class="detail-skeleton"></div>';
  try {
    const dashboard = await api('profile_dashboard');
    const profile = dashboard.profile;
    const remaining = profile.daily_download_limit === null ? '不限' : Math.max(0, profile.daily_download_limit - profile.daily_downloads);
    target.innerHTML = `<div class="metric-grid"><div class="metric-card"><span>当前积分</span><strong>${formatNumber(profile.points)}</strong></div><div class="metric-card"><span>账户等级</span><strong>Lv.${Number(profile.level)}</strong></div><div class="metric-card"><span>今日剩余下载</span><strong>${remaining}</strong></div><div class="metric-card"><span>已公开 / 已收藏</span><strong>${formatNumber(profile.published_count)} / ${formatNumber(profile.favorite_count)}</strong></div></div><section class="account-panel"><div class="account-panel__head"><h2>我的发布</h2><span class="status-pill" id="my-resource-total"></span></div><div id="my-resource-list"></div><nav class="pagination" id="my-resource-pagination" aria-label="我的发布分页"></nav></section><section class="account-panel"><div class="account-panel__head"><h2>我的收藏</h2><span class="status-pill" id="favorite-total"></span></div><div class="resource-list resource-list--grid" id="favorite-list"></div><nav class="pagination" id="favorite-pagination" aria-label="收藏分页"></nav></section><section class="account-panel"><div class="account-panel__head"><h2>修改密码</h2></div><p>修改成功后，其他设备的旧会话会失效。</p><form id="password-form" class="form-grid"><label><span>当前密码</span><input name="current_password" type="password" autocomplete="current-password" required maxlength="72"></label><label><span>新密码</span><input name="new_password" type="password" autocomplete="new-password" required minlength="10" maxlength="72"></label><label><span>再次输入新密码</span><input name="password_confirmation" type="password" autocomplete="new-password" required minlength="10" maxlength="72"></label><button class="button button--primary" type="submit">更新密码</button></form></section>`;
    await Promise.all([loadMyResources(), loadFavorites()]);
  } catch (error) {
    renderError(target, error.message, 'account');
  }
}

async function loadMyResources(page = state.myPage) {
  const target = $('#my-resource-list');
  state.myPage = page;
  target.innerHTML = '<p>正在读取我的发布</p>';
  try {
    const data = await api('my_resources', { params: { page, limit: 8 } });
    state.myPage = data.page;
    $('#my-resource-total').textContent = `${formatNumber(data.total)} 项`;
    if (data.items.length) target.innerHTML = data.items.map((item) => `<article class="own-resource">${resourceCard(item)}<div class="resource-review-note"><span class="status-pill">${resourceStatus(item.status, item.moderation_reason)}</span>${item.moderation_reason ? `<p>处理说明：${escapeHtml(item.moderation_reason)}</p>` : ''}<a class="button button--ghost" href="#/edit/${encodeURIComponent(item.id)}">编辑</a></div></article>`).join('');
    else renderEmpty(target, '还没有发布资源', '点击发布资源，审核通过后将显示在社区。');
    $('#my-resource-pagination').innerHTML = paginationMarkup(data.page, data.total_pages, 'my-page');
  } catch (error) { renderError(target, error.message, 'my-resources'); }
}

async function loadFavorites(page = state.favoritePage) {
  const target = $('#favorite-list');
  state.favoritePage = page;
  target.innerHTML = '<p>正在读取收藏</p>';
  try {
    const data = await api('favorites', { params: { page, limit: 8 } });
    state.favoritePage = data.page;
    $('#favorite-total').textContent = `${formatNumber(data.total)} 项`;
    if (data.items.length) target.innerHTML = data.items.map(resourceCard).join('');
    else renderEmpty(target, '还没有收藏', '浏览资源详情，点击收藏即可保存在这里。');
    $('#favorite-pagination').innerHTML = paginationMarkup(data.page, data.total_pages, 'favorite-page');
  } catch (error) { renderError(target, error.message, 'favorites'); }
}

function queueCard(item, queue) {
  if (queue === 'software') return `<article class="queue-card"><div><h3>${escapeHtml(item.name)} <span class="status-pill">${resourceStatus(item.status, item.moderation_reason)}</span></h3><p>发布者：${escapeHtml(item.author || '社区用户')} · ${formatDate(item.updated_at || item.created_at)}</p><p>${escapeHtml(item.summary)}</p>${item.moderation_reason ? `<p>上次处理：${escapeHtml(item.moderation_reason)}</p>` : ''}<a class="text-button" href="#/detail/${encodeURIComponent(item.id)}">查看完整资源与下载来源</a></div><form class="moderation-form" data-moderation-form data-id="${Number(item.id)}"><label><span>处理原因（驳回、下架时必填）</span><textarea name="reason" rows="2" maxlength="500" placeholder="请写明需要修改的内容或下架原因"></textarea></label><div class="queue-card__actions">${item.status !== 'published' ? '<button class="button button--primary" type="submit" data-status="published">审核通过</button>' : ''}${item.status === 'draft' ? '<button class="button button--danger" type="submit" data-status="draft">驳回</button>' : '<button class="button button--danger" type="submit" data-status="disabled">下架</button>'}${item.status !== 'draft' ? '<button class="button button--ghost" type="submit" data-status="draft">退回待审</button>' : ''}</div></form></article>`;
  const title = queue === 'reports' ? `${item.software_name} · ${item.username}` : (queue === 'dmca' ? item.claimant_name : item.type);
  const body = queue === 'reports' ? item.reason : (queue === 'dmca' ? `${item.resource_url}\n${item.statement}` : item.message);
  const status = queue === 'notifications' ? (Number(item.is_read) ? '已读' : '未读') : (({ open: '待处理', resolved: '已处理', closed: '已关闭' })[item.status] || item.status);
  const primaryStatus = queue === 'reports' ? 'resolved' : (queue === 'dmca' ? 'closed' : 'read');
  return `<article class="queue-card"><div><h3>${escapeHtml(title || `记录 #${item.id}`)} <span class="status-pill">${escapeHtml(status)}</span></h3><p>${escapeHtml(body)}</p><p>${escapeHtml(formatDate(item.created_at))}</p></div><div class="queue-card__actions"><button class="button button--primary" data-admin-resolve data-queue="${queue}" data-id="${item.id}" data-status="${primaryStatus}">${queue === 'notifications' ? '标为已读' : '完成处理'}</button></div></article>`;
}

async function loadAdminQueue(queue = state.adminQueue, page = state.adminPage) {
  const target = $('#admin-queue');
  if (!state.me) { signedOutWorkspace(target, '请登录管理员账户'); return; }
  if (state.me.role !== 'admin') { renderEmpty(target, '此页面需要管理员权限', '当前账户可以在个人中心查看自己的发布和审核状态。'); return; }
  if (state.adminQueue !== queue) page = 1;
  state.adminQueue = queue;
  state.adminPage = page;
  $('#admin-status-field').hidden = queue !== 'software';
  target.innerHTML = '<div class="detail-skeleton"></div>';
  try {
    const openStatus = queue === 'software' ? $('#admin-status').value : (queue === 'notifications' ? 'unread' : 'open');
    const data = await api('admin_queue', { params: { queue, status: openStatus, page, limit: 12 } });
    state.adminPage = data.page;
    if (data.items.length) target.innerHTML = data.items.map((item) => queueCard(item, queue)).join('');
    else renderEmpty(target, '队列已清空', '当前没有需要处理的记录。');
    $('#admin-total').textContent = `${formatNumber(data.total)} 条记录`;
    $('#admin-pagination').innerHTML = paginationMarkup(data.page, data.total_pages, 'admin-page');
  } catch (error) {
    renderError(target, error.message, 'admin');
  }
}

function currentRoute() {
  if (!location.hash && document.body.dataset.notFound === 'true') return { page: 'not-found' };
  if (!location.hash && location.pathname.startsWith('/software/') && document.body.dataset.resourceId) return { page: 'detail', id: document.body.dataset.resourceId };
  if (!location.hash && (document.body.dataset.platform || document.body.dataset.category)) return { page: 'search' };
  const hash = location.hash || '#/home';
  const path = hash.slice(2).split('?')[0].split('/').filter(Boolean);
  if (!path.length) return { page: 'home' };
  if (path[0] === 'detail' && /^\d+$/.test(path[1] || '')) return { page: 'detail', id: path[1] };
  if (path[0] === 'edit' && /^\d+$/.test(path[1] || '')) return { page: 'publish', id: path[1] };
  if (['home', 'search', 'rankings', 'publish', 'account', 'admin'].includes(path[0])) return { page: path[0] };
  return { page: 'not-found' };
}

async function route() {
  const routeInfo = currentRoute();
  $$('.page').forEach((page) => page.classList.toggle('page--active', page.dataset.page === routeInfo.page));
  $$('[data-route]').forEach((link) => {
    const active = link.dataset.route === routeInfo.page;
    link.classList.toggle('is-active', active);
    if (active) link.setAttribute('aria-current', 'page');
    else link.removeAttribute('aria-current');
  });
  closeDrawer();
  window.scrollTo({ top: 0, behavior: 'instant' });
  $('#main').focus({ preventScroll: true });
  if (routeInfo.page === 'home') await loadHome();
  if (routeInfo.page === 'search') await loadSearch();
  if (routeInfo.page === 'rankings') await loadRankings();
  if (routeInfo.page === 'detail') await loadDetail(routeInfo.id);
  if (routeInfo.page === 'publish') await loadPublish(routeInfo.id);
  if (routeInfo.page === 'account') await loadAccount();
  if (routeInfo.page === 'admin') await loadAdminQueue();
}

function openDrawer(name) {
  const element = $(`#${name}`);
  if (!element) return;
  closeDrawer(false);
  state.drawerTrigger = document.activeElement;
  state.drawer = element;
  element.inert = false;
  element.classList.add('is-open');
  element.setAttribute('aria-hidden', 'false');
  element.setAttribute('role', 'dialog');
  element.setAttribute('aria-modal', 'true');
  element.setAttribute('tabindex', '-1');
  state.drawerTrigger?.setAttribute('aria-expanded', 'true');
  let branch = element;
  while (branch.parentElement && branch !== document.body) {
    [...branch.parentElement.children].filter((sibling) => sibling !== branch && sibling.id !== 'scrim').forEach((sibling) => {
      state.inertElements.push([sibling, sibling.inert]);
      sibling.inert = true;
    });
    branch = branch.parentElement;
  }
  $('#scrim').hidden = false;
  document.body.classList.add('is-locked');
  (drawerFocusables()[0] || element).focus();
}

function drawerFocusables() {
  if (!state.drawer) return [];
  return $$('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex="0"]', state.drawer).filter((element) => element.getClientRects().length && !element.closest('[inert]'));
}

function syncDrawerVisibility() {
  if (state.drawer) return;
  $('#drawer').inert = true;
  const mobile = window.matchMedia('(max-width: 720px)').matches;
  $('#filters').inert = mobile;
  if (mobile) $('#filters').setAttribute('aria-hidden', 'true');
  else $('#filters').removeAttribute('aria-hidden');
}

function closeDrawer(restoreFocus = true) {
  const trigger = state.drawerTrigger;
  state.inertElements.forEach(([element, inert]) => { element.inert = inert; });
  state.inertElements = [];
  $$('.drawer.is-open, .filters.is-open').forEach((element) => {
    element.classList.remove('is-open');
    element.setAttribute('aria-hidden', 'true');
    element.removeAttribute('role');
    element.removeAttribute('aria-modal');
  });
  state.drawer = null;
  state.drawerTrigger = null;
  trigger?.setAttribute('aria-expanded', 'false');
  $('#scrim').hidden = true;
  document.body.classList.remove('is-locked');
  syncDrawerVisibility();
  if (restoreFocus && trigger?.isConnected) trigger.focus();
}

function showDialog(id) {
  if (id === 'dead-link' && (!state.currentResource || currentRoute().page !== 'detail')) { toast('请先打开资源详情，再提交链接举报。', 'error'); return; }
  closeDrawer();
  const dialog = $(`#${id}-modal`) || $(`#${id}`);
  if (dialog && !dialog.open) dialog.showModal();
}

function closeDialog(button) {
  const dialog = button.closest('dialog');
  if (dialog) dialog.close();
}

function toast(message, type = 'success') {
  const element = document.createElement('div');
  element.className = `toast ${type === 'error' ? 'toast--error' : ''}`;
  element.textContent = message;
  $('#toast-region').append(element);
  window.setTimeout(() => element.remove(), 3200);
}

function showPwaUpdate() {
  const banner = $('#pwa-update');
  if (banner) banner.hidden = false;
}

async function clearPwaCaches() {
  if (!('serviceWorker' in navigator)) return;
  try {
    const registration = await navigator.serviceWorker.ready;
    const worker = registration.active;
    if (!worker) throw new Error('Service Worker 尚未激活');
    await new Promise((resolve, reject) => {
      const channel = new MessageChannel();
      const timeout = window.setTimeout(() => reject(new Error('缓存清理超时')), 5000);
      channel.port1.onmessage = (event) => {
        window.clearTimeout(timeout);
        if (event.data?.ok) resolve();
        else reject(new Error('缓存清理失败'));
      };
      worker.postMessage({ type: 'CLEAR_CACHES' }, [channel.port2]);
    });
    toast('离线缓存已清理并重建');
  } catch (error) {
    console.error('[PWA] Cache cleanup failed.', error);
    toast('离线缓存清理失败', 'error');
  }
}

async function registerServiceWorker() {
  if (!('serviceWorker' in navigator) || location.protocol === 'file:') return;
  try {
    const hadController = Boolean(navigator.serviceWorker.controller);
    const registration = await navigator.serviceWorker.register('/sw.js');
    if (registration.waiting && hadController) showPwaUpdate();
    registration.addEventListener('updatefound', () => {
      const installing = registration.installing;
      installing?.addEventListener('statechange', () => {
        if (installing.state === 'installed' && navigator.serviceWorker.controller) showPwaUpdate();
      });
    });
    navigator.serviceWorker.addEventListener('controllerchange', () => {
      if (hadController) showPwaUpdate();
    }, { once: true });
  } catch (error) {
    console.error('[PWA] Service Worker registration failed.', error);
  }
}

async function copyText(value) {
  try {
    await navigator.clipboard.writeText(value);
    toast('已复制到剪贴板');
  } catch {
    const input = document.createElement('textarea');
    input.value = value;
    input.style.position = 'fixed';
    input.style.opacity = '0';
    document.body.append(input);
    input.select();
    document.execCommand('copy');
    input.remove();
    toast('已复制到剪贴板');
  }
}

async function loadMe() {
  try {
    const data = await api('me');
    state.me = data.user || data;
    if (!state.me?.id) state.me = null;
  } catch {
    state.me = null;
  }
  const name = state.me ? safeText(state.me.username || state.me.name, '用户') : '访客';
  $('#profile-name').textContent = name;
  $('#profile-avatar').textContent = Array.from(name)[0];
  $$('[data-admin-link]').forEach((link) => { link.hidden = state.me?.role !== 'admin'; });
}

function profileDialogMarkup(mode = 'login') {
  const existing = $('#profile-modal');
  if (existing) existing.remove();
  const dialog = document.createElement('dialog');
  dialog.className = 'modal';
  dialog.id = 'profile-modal';
  if (state.me) {
    const role = state.me.role === 'admin' ? '管理员' : (state.me.is_vip || state.me.role === 'vip' ? 'VIP 用户' : '普通用户');
    dialog.innerHTML = `<div class="modal__panel"><div class="modal__head"><div><span class="section-kicker">My account</span><h2>${escapeHtml(state.me.username || state.me.name)}</h2></div><button class="icon-button" data-dialog-close aria-label="关闭"><svg><use href="#i-close"/></svg></button></div><div class="legal-copy"><p>账户等级：<strong>${role}</strong></p><p>当前积分：<strong>${formatNumber(state.me.points)}</strong></p><p>今日下载：<strong>${formatNumber(state.me.daily_downloads || state.me.downloads_today)}</strong></p></div><div class="action-row"><a class="button button--ghost" href="#/account" data-dialog-close><svg><use href="#i-dashboard"/></svg>个人中心</a><a class="button button--ghost" href="#/publish" data-dialog-close><svg><use href="#i-plus"/></svg>发布资源</a>${state.me.role === 'admin' ? '<a class="button button--ghost" href="#/admin" data-dialog-close>管理工作台</a>' : ''}</div><div class="action-row"><button class="button button--primary" id="checkin-button">每日签到</button><button class="button button--ghost" id="logout-button">退出登录</button></div></div>`;
  } else if (mode === 'register') {
    dialog.innerHTML = `<form class="modal__panel" id="register-form"><div class="modal__head"><div><span class="section-kicker">Create account</span><h2>注册社区账户</h2></div><button class="icon-button" type="button" data-dialog-close aria-label="关闭"><svg><use href="#i-close"/></svg></button></div><label><span>用户名</span><input name="username" autocomplete="username" minlength="3" maxlength="24" required></label><label><span>密码</span><input name="password" type="password" autocomplete="new-password" minlength="10" maxlength="72" required></label><button class="button button--primary button--block" type="submit">创建账户</button><button class="text-button" type="button" data-auth-mode="login">已有账户？返回登录</button></form>`;
  } else {
    dialog.innerHTML = `<form class="modal__panel" id="login-form"><div class="modal__head"><div><span class="section-kicker">Member login</span><h2>登录社区账户</h2></div><button class="icon-button" type="button" data-dialog-close aria-label="关闭"><svg><use href="#i-close"/></svg></button></div><label><span>用户名</span><input name="username" autocomplete="username" required></label><label><span>密码</span><input name="password" type="password" autocomplete="current-password" minlength="10" required></label><button class="button button--primary button--block" type="submit">登录</button><button class="text-button" type="button" data-auth-mode="register">还没有账户？立即注册</button></form>`;
  }
  document.body.append(dialog);
  dialog.showModal();
}

async function submitForm(form, action, successMessage) {
  const button = $('button[type="submit"]', form);
  const values = Object.fromEntries(new FormData(form));
  if (button) button.disabled = true;
  try {
    await api(action, { method: 'POST', params: values });
    toast(successMessage);
    form.closest('dialog')?.close();
    form.reset();
    return true;
  } catch (error) {
    toast(error.message, 'error');
    return false;
  } finally {
    if (button) button.disabled = false;
  }
}

function bindEvents() {
  window.addEventListener('hashchange', route);
  window.addEventListener('popstate', (event) => {
    if (location.hash) return;
    if (event.state?.resourceId) document.body.dataset.resourceId = event.state.resourceId;
    route();
  });
  window.matchMedia('(max-width: 720px)').addEventListener('change', () => { closeDrawer(); syncDrawerVisibility(); });
  $('#drawer-open').addEventListener('click', () => openDrawer('drawer'));
  $('#filter-open').addEventListener('click', () => openDrawer('filters'));
  $('#scrim').addEventListener('click', closeDrawer);
  $('#search-open').addEventListener('click', () => showDialog('search'));
  $('#hero-search').addEventListener('click', () => showDialog('search'));
  $('#profile-button').addEventListener('click', profileDialogMarkup);
  $('#bottom-profile').addEventListener('click', profileDialogMarkup);
  $('#pwa-update-refresh').addEventListener('click', () => location.reload());
  $('#pwa-cache-clear').addEventListener('click', clearPwaCaches);

  document.addEventListener('keydown', (event) => {
    if (state.drawer) {
      if (event.key === 'Escape') { event.preventDefault(); closeDrawer(); }
      if (event.key === 'Tab') {
        const focusables = drawerFocusables();
        const first = focusables[0];
        const last = focusables[focusables.length - 1];
        if (!first) { event.preventDefault(); state.drawer.focus(); }
        else if (event.shiftKey && (document.activeElement === first || !state.drawer.contains(document.activeElement))) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && (document.activeElement === last || !state.drawer.contains(document.activeElement))) { event.preventDefault(); first.focus(); }
      }
      return;
    }
    const tab = event.target.closest('[role="tab"]');
    if (tab && ['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
      const tabs = $$('[role="tab"]', tab.closest('[role="tablist"]'));
      const index = tabs.indexOf(tab);
      const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
      event.preventDefault();
      tabs[next].focus();
      tabs[next].click();
      return;
    }
    if (event.key === '/' && !['INPUT', 'TEXTAREA'].includes(document.activeElement.tagName)) {
      event.preventDefault();
      showDialog('search');
      $('#global-query').focus();
    }
  });

  document.addEventListener('click', async (event) => {
    if (event.target.closest('.skip-link')) { event.preventDefault(); $('#main').focus(); return; }
    const closeButton = event.target.closest('[data-close]');
    if (closeButton) closeDrawer();
    const dialogClose = event.target.closest('[data-dialog-close]');
    if (dialogClose) closeDialog(dialogClose);
    const modalButton = event.target.closest('[data-modal]');
    if (modalButton) showDialog(modalButton.dataset.modal);
    const copyButton = event.target.closest('[data-copy]');
    if (copyButton) await copyText(copyButton.dataset.copy);
    const sourceButton = event.target.closest('[data-source-id]');
    if (sourceButton) await requestDownload(sourceButton.dataset.sourceId, sourceButton);
    const authMode = event.target.closest('[data-auth-mode]');
    if (authMode) profileDialogMarkup(authMode.dataset.authMode);
    const pageButton = event.target.closest('button[data-page]');
    if (pageButton && !pageButton.disabled) location.hash = filterUrl({ ...filtersFromUrl(), page: Number(pageButton.dataset.page) });
    const myPage = event.target.closest('button[data-my-page]');
    if (myPage && !myPage.disabled) await loadMyResources(Number(myPage.dataset.myPage));
    const favoritePage = event.target.closest('button[data-favorite-page]');
    if (favoritePage && !favoritePage.disabled) await loadFavorites(Number(favoritePage.dataset.favoritePage));
    const adminPage = event.target.closest('button[data-admin-page]');
    if (adminPage && !adminPage.disabled) await loadAdminQueue(state.adminQueue, Number(adminPage.dataset.adminPage));
    const retry = event.target.closest('[data-retry]');
    if (retry) {
      const loaders = { home: loadHome, search: loadSearch, rankings: loadRankings, account: loadAccount, 'my-resources': loadMyResources, favorites: loadFavorites, admin: loadAdminQueue, publish: () => loadPublish(currentRoute().id) };
      await loaders[retry.dataset.retry]?.();
    }
    if (event.target.closest('[data-focus-reply]')) $('#reply-content')?.focus();
    const queryButton = event.target.closest('[data-query]');
    if (queryButton) { $('#global-query').value = queryButton.dataset.query; $('#global-query').focus(); }
    if (event.target.closest('[data-open-login]')) profileDialogMarkup();
    if (event.target.closest('#add-source-button')) {
      const editor = $('#source-editor');
      if ($$('.source-editor__row', editor).length < 10) editor.insertAdjacentHTML('beforeend', sourceEditorRow());
      else toast('最多添加 10 个下载源', 'error');
    }
    const removeSource = event.target.closest('[data-remove-source]');
    if (removeSource) {
      const rows = $$('.source-editor__row', $('#source-editor'));
      if (rows.length > 1) removeSource.closest('.source-editor__row').remove();
      else toast('至少保留一个下载源', 'error');
    }
    const favoriteButton = event.target.closest('[data-favorite-id]');
    if (favoriteButton) {
      if (!state.me) { profileDialogMarkup(); }
      else {
        try {
          const data = await api('favorite_toggle', { method: 'POST', params: { software_id: Number(favoriteButton.dataset.favoriteId) } });
          favoriteButton.classList.toggle('is-active', Boolean(data.favorited));
          favoriteButton.setAttribute('aria-pressed', String(Boolean(data.favorited)));
          $('span', favoriteButton).textContent = data.favorited ? '已收藏' : '收藏';
          toast(data.favorited ? '已加入收藏' : '已取消收藏');
        } catch (error) { toast(error.message, 'error'); }
      }
    }
    const adminTab = event.target.closest('[data-admin-queue]');
    if (adminTab) {
      selectTab(adminTab);
      await loadAdminQueue(adminTab.dataset.adminQueue);
    }
    const resolveButton = event.target.closest('[data-admin-resolve]');
    if (resolveButton) {
      resolveButton.disabled = true;
      try {
        await api('admin_resolve', { method: 'POST', params: { queue: resolveButton.dataset.queue, id: Number(resolveButton.dataset.id), status: resolveButton.dataset.status } });
        toast('处理状态已更新');
        await loadAdminQueue(resolveButton.dataset.queue);
      } catch (error) { toast(error.message, 'error'); resolveButton.disabled = false; }
    }
    if (event.target.closest('#checkin-button')) {
      try {
        const data = await api('checkin', { method: 'POST' });
        toast(`签到成功，获得 ${Number(data.points_awarded || data.points || 0)} 积分`);
        await loadMe();
        $('#profile-modal')?.close();
      } catch (error) { toast(error.message, 'error'); }
    }
    if (event.target.closest('#logout-button')) {
      try { await api('logout', { method: 'POST' }); await loadMe(); $('#profile-modal')?.close(); location.hash = '#/home'; toast('已退出登录'); } catch (error) { toast(error.message, 'error'); }
    }
  });

  document.addEventListener('submit', async (event) => {
    if (event.target.matches('[data-moderation-form]')) {
      event.preventDefault();
      const form = event.target;
      const button = event.submitter;
      if (!button?.dataset.status) return;
      const reason = form.elements.reason.value.trim();
      if (button.dataset.status !== 'published' && reason.length < 3) { toast('请填写至少 3 字的处理原因。', 'error'); form.elements.reason.focus(); return; }
      const buttons = $$('button', form);
      buttons.forEach((entry) => { entry.disabled = true; });
      try {
        await api('admin_resolve', { method: 'POST', params: { queue: 'software', id: Number(form.dataset.id), status: button.dataset.status, reason } });
        toast('审核状态已更新');
        await loadAdminQueue('software');
      } catch (error) { toast(error.message, 'error'); buttons.forEach((entry) => { entry.disabled = false; }); }
    }
    if (event.target.id === 'password-form') {
      event.preventDefault();
      const form = event.target;
      if (form.elements.new_password.value !== form.elements.password_confirmation.value) { toast('两次输入的新密码不一致。', 'error'); form.elements.password_confirmation.focus(); return; }
      const button = $('button[type="submit"]', form);
      button.disabled = true;
      try {
        await api('change_password', { method: 'POST', params: { current_password: form.elements.current_password.value, new_password: form.elements.new_password.value } });
        form.reset();
        await loadMe();
        toast('密码已更新，其他设备需要重新登录。');
      } catch (error) { toast(error.message, 'error'); }
      finally { button.disabled = false; }
    }
    if (event.target.id === 'reply-form') {
      event.preventDefault();
      if (!state.me) { profileDialogMarkup(); return; }
      const form = event.target;
      const success = await submitForm(form, 'reply', '回复已发布，正在刷新访问状态');
      if (success && state.currentResource) await loadDetail(state.currentResource.id);
    }
    if (event.target.id === 'login-form') {
      event.preventDefault();
      const success = await submitForm(event.target, 'login', '登录成功');
      if (success) { await loadMe(); await route(); }
    }
    if (event.target.id === 'register-form') {
      event.preventDefault();
      const success = await submitForm(event.target, 'register', '注册成功');
      if (success) { await loadMe(); await route(); }
    }
    if (event.target.id === 'publish-form') {
      event.preventDefault();
      await submitPublish(event.target);
    }
  });

  $('#global-search-form').addEventListener('submit', (event) => {
    event.preventDefault();
    const value = $('#global-query').value.trim();
    if (!value) return;
    $('#search-modal').close();
    location.hash = filterUrl({ q: value, platform: [], sort: 'updated', page: 1 });
  });

  $('#filter-form').addEventListener('submit', (event) => {
    event.preventDefault();
    const form = new FormData(event.target);
    closeDrawer();
    location.hash = filterUrl({ q: form.get('q').trim(), platform: form.getAll('platform'), category: filtersFromUrl().category, updated: form.get('updated'), access: form.get('access'), sort: $('#sort-select').value, page: 1 });
  });
  $('#filter-reset').addEventListener('click', () => window.setTimeout(() => { location.hash = '#/search'; if (location.hash === '#/search') loadSearch(); }, 0));
  $('#sort-select').addEventListener('change', (event) => { location.hash = filterUrl({ ...filtersFromUrl(), sort: event.target.value, page: 1 }); });
  $('#admin-status').addEventListener('change', () => loadAdminQueue('software', 1));
  $$('.tabs [data-ranking]').forEach((button) => button.addEventListener('click', () => {
    selectTab(button);
    renderLeaderboard(button.dataset.ranking);
  }));

  $('#agreement-check').addEventListener('change', (event) => { $('#agreement-accept').disabled = !event.target.checked; });
  $('#agreement-accept').addEventListener('click', async () => {
    localStorage.setItem(AGREEMENT_KEY, new Date().toISOString());
    if (state.me) { try { await api('agreement', { method: 'POST', params: { accepted: true } }); } catch { /* Local acceptance remains valid. */ } }
  });

  $('#dmca-form').addEventListener('submit', async (event) => { event.preventDefault(); await submitForm(event.target, 'dmca', '申请已提交，我们会尽快核验处理'); });
  $('#dead-link-form').addEventListener('submit', async (event) => { event.preventDefault(); await submitForm(event.target, 'report', '举报已提交，感谢你的反馈'); });

  $$('dialog').forEach((dialog) => dialog.addEventListener('click', (event) => {
    if (event.target === dialog && dialog.id !== 'agreement-modal') dialog.close();
  }));
}

function selectTab(selected) {
  $$('[role="tab"]', selected.closest('[role="tablist"]')).forEach((tab) => {
    const active = tab === selected;
    tab.setAttribute('aria-selected', String(active));
    tab.tabIndex = active ? 0 : -1;
  });
  const panel = document.getElementById(selected.getAttribute('aria-controls'));
  if (panel) panel.setAttribute('aria-labelledby', selected.id);
}

async function init() {
  const serviceWorkerRegistration = registerServiceWorker();
  renderPlatformNavigation();
  initializePublishForm();
  bindEvents();
  syncDrawerVisibility();
  await loadMe();
  if (!localStorage.getItem(AGREEMENT_KEY)) showDialog('agreement');
  if (!location.hash && document.body.dataset.notFound !== 'true' && !document.body.dataset.resourceId && !document.body.dataset.platform && !document.body.dataset.category) history.replaceState(null, '', '#/home');
  await route();
  await serviceWorkerRegistration;
}

init();
