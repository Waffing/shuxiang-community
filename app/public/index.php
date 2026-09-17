<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
$seo = App\Seo::page($_SERVER['REQUEST_URI'] ?? '/');
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=0, must-revalidate');
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="#5b67f1">
  <meta name="description" content="<?= $escape($seo['description']) ?>">
  <link rel="canonical" href="<?= $escape($seo['canonical']) ?>">
  <meta property="og:type" content="website">
  <meta property="og:title" content="<?= $escape($seo['title']) ?>">
  <meta property="og:description" content="<?= $escape($seo['description']) ?>">
  <meta property="og:url" content="<?= $escape($seo['canonical']) ?>">
  <meta property="og:image" content="<?= $escape(App\Seo::origin()) ?>/assets/share.png">
  <meta name="twitter:card" content="summary_large_image">
  <?php if ($seo['not_found']): ?><meta name="robots" content="noindex"><?php endif; ?>
  <meta name="color-scheme" content="light">
  <title><?= $escape($seo['title']) ?></title>
  <link rel="manifest" href="/manifest.json">
  <link rel="icon" href="/assets/icon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="/assets/styles.min.css?v=20260917-1">
  <?php if ($seo['resource']): ?>
  <script type="application/ld+json"><?= json_encode(['@context' => 'https://schema.org', '@type' => 'SoftwareApplication', 'name' => $seo['resource']['name'], 'softwareVersion' => $seo['resource']['version'], 'description' => $seo['resource']['summary'], 'url' => $seo['canonical'], 'dateModified' => $seo['resource']['updated_at']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
  <?php endif; ?>
</head>
<body data-resource-id="<?= $escape($seo['resource']['id'] ?? '') ?>" data-platform="<?= $escape($seo['platform']) ?>" data-category="<?= $escape($seo['category']) ?>" data-not-found="<?= $seo['not_found'] ? 'true' : 'false' ?>">
<a class="skip-link" href="#main">跳到主要内容</a>
<svg class="svg-sprite" aria-hidden="true" focusable="false">
  <symbol id="i-logo" viewBox="0 0 48 48"><rect width="48" height="48" rx="15" fill="url(#logo-g)"/><path d="M14 16.5h20M14 24h20M14 31.5h13" fill="none" stroke="#fff" stroke-width="4" stroke-linecap="round"/><circle cx="33" cy="31.5" r="3" fill="#bff5ff"/></symbol>
  <symbol id="i-menu" viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></symbol>
  <symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="6.5"/><path d="m16 16 4 4"/></symbol>
  <symbol id="i-home" viewBox="0 0 24 24"><path d="m3 11 9-8 9 8v9a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1Z"/></symbol>
  <symbol id="i-grid" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/></symbol>
  <symbol id="i-rank" viewBox="0 0 24 24"><path d="M5 20v-7h4v7M10 20V4h4v16M15 20v-11h4v11M3 20h18"/></symbol>
  <symbol id="i-user" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></symbol>
  <symbol id="i-download" viewBox="0 0 24 24"><path d="M12 3v12m-5-5 5 5 5-5M4 20h16"/></symbol>
  <symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
  <symbol id="i-fire" viewBox="0 0 24 24"><path d="M13.5 2.8c.6 4-2.9 4.8-2 8.2 1-1.5 2.2-2.3 3.5-3.3 2.1 1.8 3.5 4.1 3.5 6.8a6.5 6.5 0 0 1-13 0c0-3.1 1.7-6 5.1-9.1.1 2 .7 3 1.3 3.6.3-2.1.9-4.2 1.6-6.2Z"/></symbol>
  <symbol id="i-star" viewBox="0 0 24 24"><path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-2.9-5.6 2.9 1.1-6.2L3 9.6l6.2-.9Z"/></symbol>
  <symbol id="i-shield" viewBox="0 0 24 24"><path d="M12 3 4.5 6v5.5c0 4.7 3.2 8.1 7.5 9.5 4.3-1.4 7.5-4.8 7.5-9.5V6Z"/><path d="m8.5 12 2.2 2.2 4.8-5"/></symbol>
  <symbol id="i-copy" viewBox="0 0 24 24"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3"/></symbol>
  <symbol id="i-message" viewBox="0 0 24 24"><path d="M21 11.5a8.5 8.5 0 0 1-9 8.5 10 10 0 0 1-4-.8L3 21l1.7-4.2A8.2 8.2 0 0 1 3 11.5a8.5 8.5 0 0 1 9-8.5 8.5 8.5 0 0 1 9 8.5Z"/></symbol>
  <symbol id="i-alert" viewBox="0 0 24 24"><path d="M10.3 4.1 2.6 18a2 2 0 0 0 1.8 3h15.2a2 2 0 0 0 1.8-3L13.7 4.1a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4m0 4h.01"/></symbol>
  <symbol id="i-chevron" viewBox="0 0 24 24"><path d="m9 5 7 7-7 7"/></symbol>
  <symbol id="i-close" viewBox="0 0 24 24"><path d="m5 5 14 14M19 5 5 19"/></symbol>
  <symbol id="i-filter" viewBox="0 0 24 24"><path d="M4 6h16M7 12h10M10 18h4"/></symbol>
  <symbol id="i-check" viewBox="0 0 24 24"><path d="m5 12 4.5 4.5L19 7"/></symbol>
  <symbol id="i-external" viewBox="0 0 24 24"><path d="M14 4h6v6M20 4 11 13M20 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h5"/></symbol>
  <symbol id="i-lock" viewBox="0 0 24 24"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></symbol>
  <symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
  <symbol id="i-heart" viewBox="0 0 24 24"><path d="M20.8 4.8a5.5 5.5 0 0 0-7.8 0L12 5.9l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.4a5.5 5.5 0 0 0 0-7.8Z"/></symbol>
  <symbol id="i-dashboard" viewBox="0 0 24 24"><rect x="3" y="3" width="8" height="8" rx="2"/><rect x="13" y="3" width="8" height="5" rx="2"/><rect x="13" y="10" width="8" height="11" rx="2"/><rect x="3" y="13" width="8" height="8" rx="2"/></symbol>
  <defs><linearGradient id="logo-g" x1="4" y1="3" x2="44" y2="45"><stop stop-color="#6d5dfc"/><stop offset="1" stop-color="#19b9e6"/></linearGradient></defs>
</svg>

<div id="app" class="app-shell">
  <header class="topbar">
    <div class="topbar__inner">
      <button class="icon-button mobile-only" id="drawer-open" aria-label="打开导航" aria-controls="drawer" aria-expanded="false"><svg><use href="#i-menu"/></svg></button>
      <a class="brand" href="#/home" aria-label="数享社区首页"><svg class="brand__mark"><use href="#i-logo"/></svg><span>数享社区</span></a>
      <nav class="desktop-nav" aria-label="主导航">
        <a href="#/home" data-route="home"><svg><use href="#i-home"/></svg>首页</a>
        <a href="#/search" data-route="search"><svg><use href="#i-grid"/></svg>软件库</a>
        <a href="#/rankings" data-route="rankings"><svg><use href="#i-rank"/></svg>排行榜</a>
        <a href="#/publish" data-route="publish"><svg><use href="#i-plus"/></svg>发布</a>
      </nav>
      <button class="search-trigger" id="search-open" aria-label="搜索软件"><svg><use href="#i-search"/></svg><span>搜索软件、版本或平台</span><kbd>/</kbd></button>
      <button class="profile-button" id="profile-button" aria-label="用户中心"><span id="profile-avatar">访</span><span class="desktop-only" id="profile-name">访客</span></button>
    </div>
  </header>

  <aside class="drawer" id="drawer" aria-hidden="true" aria-label="社区导航" inert>
    <div class="drawer__head"><a class="brand" href="#/home"><svg class="brand__mark"><use href="#i-logo"/></svg><span>数享社区</span></a><button class="icon-button" data-close="drawer" aria-label="关闭导航"><svg><use href="#i-close"/></svg></button></div>
    <nav class="drawer__nav" aria-label="移动端导航">
      <a href="#/home" data-route="home"><svg><use href="#i-home"/></svg>首页</a>
      <a href="#/search" data-route="search"><svg><use href="#i-grid"/></svg>软件资源库</a>
      <a href="#/rankings" data-route="rankings"><svg><use href="#i-rank"/></svg>下载排行榜</a>
      <a href="#/publish" data-route="publish"><svg><use href="#i-plus"/></svg>发布资源</a>
      <a href="#/account" data-route="account"><svg><use href="#i-user"/></svg>个人中心</a>
      <a href="#/admin" data-route="admin" data-admin-link hidden><svg><use href="#i-dashboard"/></svg>管理工作台</a>
    </nav>
    <div class="drawer__tip"><strong>资源发布规范</strong><p>发布前请确认版权许可、文件完整性与版本信息。</p></div>
  </aside>
  <div class="scrim" id="scrim" hidden></div>

  <main id="main" tabindex="-1">
    <section class="page<?= !$seo['resource'] && !$seo['not_found'] ? ' page--active' : '' ?>" id="page-home" data-page="home" aria-labelledby="home-title">
      <div class="hero page-width">
        <div class="hero__content"><span class="eyebrow">发现 · 分享 · 交流</span><h1 id="home-title">发现适合你的<br><em>数字工具与资源</em></h1><p>按平台查找软件，查看版本记录、来源与社区使用反馈。</p><div class="hero__actions"><a class="button button--primary" href="#/search">探索软件库</a><button class="button button--ghost" id="hero-search"><svg><use href="#i-search"/></svg>快速搜索</button></div></div>
        <div class="hero__visual" aria-hidden="true"><div class="orb orb--one"></div><div class="orb orb--two"></div><div class="float-card float-card--main"><span class="mini-icon">S</span><div><strong>高效工具</strong><small>社区精选 · 多平台</small></div><span class="trust-dot"><svg><use href="#i-check"/></svg></span></div><div class="float-card float-card--sub"><svg><use href="#i-shield"/></svg><span><strong>核验信息</strong><small>以实际记录为准</small></span></div></div>
      </div>
      <div class="page-width home-content">
        <section class="category-strip" aria-labelledby="category-title"><div class="section-heading"><div><span class="section-kicker">快速入口</span><h2 id="category-title">按平台浏览</h2></div><a href="#/search">查看全部 <svg><use href="#i-chevron"/></svg></a></div><div class="platform-grid" id="platform-grid"></div></section>
        <div class="content-grid">
          <section aria-labelledby="latest-title"><div class="section-heading"><div><span class="section-kicker">Fresh releases</span><h2 id="latest-title">最新更新</h2></div><a href="#/search?sort=updated">更多更新 <svg><use href="#i-chevron"/></svg></a></div><div class="resource-list" id="latest-list" aria-live="polite"></div></section>
          <aside class="ranking-card" aria-labelledby="weekly-title"><div class="ranking-card__head"><span class="ranking-icon"><svg><use href="#i-fire"/></svg></span><div><span class="section-kicker">Trending</span><h2 id="weekly-title">周下载榜</h2></div></div><ol class="ranking-list" id="weekly-ranking"></ol><a class="ranking-card__more" href="#/rankings">查看完整榜单 <svg><use href="#i-chevron"/></svg></a></aside>
        </div>
      </div>
    </section>

    <section class="page page-width" id="page-search" data-page="search" aria-labelledby="search-title">
      <div class="page-header"><div><span class="section-kicker">Resource library</span><h1 id="search-title">软件资源库</h1><p>使用平台、更新时间和访问权限组合筛选。</p></div><button class="button button--ghost mobile-only" id="filter-open" aria-controls="filters" aria-expanded="false"><svg><use href="#i-filter"/></svg>筛选</button></div>
      <div class="library-layout">
        <aside class="filters" id="filters" aria-label="搜索筛选"><div class="filters__mobile-head"><strong>筛选条件</strong><button class="icon-button" data-close="filters" aria-label="关闭筛选"><svg><use href="#i-close"/></svg></button></div>
          <form id="filter-form">
            <label class="field-label" for="library-query">关键词</label><div class="input-with-icon"><svg><use href="#i-search"/></svg><input id="library-query" name="q" type="search" placeholder="名称、版本、简介" autocomplete="off"></div>
            <fieldset><legend>系统平台</legend><div class="check-grid" id="platform-filters"></div></fieldset>
            <fieldset><legend>更新时间</legend><label class="radio-row"><input type="radio" name="updated" value="" checked><span>不限</span></label><label class="radio-row"><input type="radio" name="updated" value="7"><span>最近 7 天</span></label><label class="radio-row"><input type="radio" name="updated" value="30"><span>最近 30 天</span></label></fieldset>
            <fieldset><legend>访问权限</legend><label class="radio-row"><input type="radio" name="access" value="" checked><span>全部资源</span></label><label class="radio-row"><input type="radio" name="access" value="free"><span>免费</span></label><label class="radio-row"><input type="radio" name="access" value="vip"><span>VIP</span></label></fieldset>
            <button class="button button--primary button--block" type="submit">应用筛选</button><button class="text-button" type="reset" id="filter-reset">清除条件</button>
          </form>
        </aside>
        <section class="search-results" aria-labelledby="results-title"><div class="results-toolbar"><p id="results-title" aria-live="polite">正在加载资源…</p><label>排序<select id="sort-select"><option value="updated">最新更新</option><option value="popular">热门程度</option><option value="downloads">下载最多</option></select></label></div><div class="resource-list resource-list--grid" id="search-results"></div><nav class="pagination" id="pagination" aria-label="分页"></nav></section>
      </div>
    </section>

    <section class="page page-width" id="page-rankings" data-page="rankings" aria-labelledby="rankings-title">
      <div class="page-header"><div><span class="section-kicker">Community charts</span><h1 id="rankings-title">热门榜单</h1><p>依据真实下载与更新时间生成，帮助快速发现优质资源。</p></div></div>
      <div class="tabs" role="tablist" aria-label="排行榜类型"><button id="ranking-weekly" role="tab" aria-selected="true" aria-controls="leaderboard" data-ranking="weekly">周下载榜</button><button id="ranking-latest" role="tab" aria-selected="false" aria-controls="leaderboard" tabindex="-1" data-ranking="latest">最新更新榜</button></div><div class="leaderboard" id="leaderboard" role="tabpanel" aria-labelledby="ranking-weekly" aria-live="polite" tabindex="0"></div>
    </section>

    <section class="page page-width<?= $seo['resource'] ? ' page--active' : '' ?>" id="page-detail" data-page="detail" aria-labelledby="detail-title"><div id="detail-view" aria-live="polite"><?php if ($seo['resource']): ?><article class="detail-card"><h1 id="detail-title"><?= $escape($seo['resource']['name']) ?></h1><p><?= $escape($seo['resource']['version']) ?></p><p><?= $escape($seo['resource']['summary']) ?></p><p><?= $escape($seo['resource']['description']) ?></p><a href="/">浏览其他软件</a></article><?php endif; ?></div></section>

    <section class="page page-width" id="page-publish" data-page="publish" aria-labelledby="publish-title">
      <div class="page-header"><div><span class="section-kicker">Creator center</span><h1 id="publish-title">发布软件资源</h1><p id="publish-description">普通用户提交或修改后进入待审核；审核通过后公开。</p></div></div>
      <div id="publish-feedback" aria-live="polite"></div>
      <form class="workspace-card publish-form" id="publish-form">
        <div class="form-section"><span class="form-step">01</span><div><h2>基础信息</h2><p>名称、版本与简介会直接显示在资源列表。</p></div></div>
        <div class="form-grid">
          <label><span>软件名称</span><input name="name" minlength="2" maxlength="100" required placeholder="例如：星云笔记"></label>
          <label><span>版本号</span><input name="version" maxlength="40" required placeholder="v1.0.0"></label>
          <label><span>分类</span><select name="category" required><option value="效率">效率</option><option value="设计">设计</option><option value="开发">开发</option><option value="影音">影音</option><option value="系统">系统</option></select></label>
          <label><span>分类色</span><input name="category_color" type="color" value="#536DFE"></label>
          <label class="form-grid__full"><span>一句话简介</span><input name="summary" minlength="10" maxlength="240" required></label>
          <label class="form-grid__full"><span>详细介绍</span><textarea name="description" minlength="20" maxlength="5000" rows="6" required></textarea></label>
          <label class="form-grid__full"><span>更新日志（每行一项）</span><textarea name="changelog" rows="4" placeholder="新增离线模式&#10;优化启动速度"></textarea></label>
        </div>
        <fieldset class="platform-picker"><legend>支持平台</legend><div class="platform-checks" id="publish-platforms"></div></fieldset>
        <div class="form-section"><span class="form-step">02</span><div><h2>访问规则</h2><p>合理设置积分或回复门槛，避免资源被批量搬运。</p></div></div>
        <div class="rule-grid"><label class="toggle-row"><input name="reply_required" type="checkbox"><span>回复后可见</span></label><label><span>所需积分</span><input name="points_required" type="number" min="0" max="100000" value="0"></label><label class="toggle-row"><input name="is_vip" type="checkbox"><span>VIP 专享</span></label><label><span>解压密码（可选）</span><input name="archive_password" maxlength="100" autocomplete="off"></label></div>
        <div class="form-section"><span class="form-step">03</span><div><h2>下载来源</h2><p>至少填写一个有效 HTTP(S) 网盘或官方下载地址。</p></div></div>
        <div class="source-editor" id="source-editor"></div><button class="button button--ghost" id="add-source-button" type="button"><svg><use href="#i-plus"/></svg>增加下载源</button>
        <div class="form-grid hash-editor"><label><span>文件 MD5（可选）</span><input name="md5" pattern="[A-Fa-f0-9]{32}" maxlength="32" spellcheck="false" title="32 位十六进制哈希值"></label><label><span>文件 SHA-256（可选）</span><input name="sha256" pattern="[A-Fa-f0-9]{64}" maxlength="64" spellcheck="false" title="64 位十六进制哈希值"></label></div>
        <label class="upload-field"><span>软件图标（可选）</span><input name="icon" type="file" accept="image/png,image/jpeg,image/webp"><small>自动居中裁切并转换为 256×256 WebP；未上传时使用名称首字母 SVG。</small></label>
        <button class="button button--primary button--block" type="submit">提交资源</button>
      </form>
    </section>

    <section class="page page-width" id="page-account" data-page="account" aria-labelledby="account-title">
      <div class="page-header"><div><span class="section-kicker">Member center</span><h1 id="account-title">个人中心</h1><p>查看积分、下载额度、我的发布和审核进度。</p></div><a class="button button--primary" href="#/publish"><svg><use href="#i-plus"/></svg>发布资源</a></div>
      <div id="account-dashboard" aria-live="polite"></div>
    </section>

    <section class="page page-width" id="page-admin" data-page="admin" aria-labelledby="admin-title">
      <div class="page-header"><div><span class="section-kicker">Operations</span><h1 id="admin-title">管理工作台</h1><p>审核资源发布，处理失效链接、版权申请和站内通知。</p></div></div>
      <div class="tabs" id="admin-tabs" role="tablist" aria-label="管理队列"><button id="admin-software" role="tab" aria-selected="true" aria-controls="admin-queue" data-admin-queue="software">资源审核</button><button id="admin-reports" role="tab" aria-selected="false" aria-controls="admin-queue" tabindex="-1" data-admin-queue="reports">失效举报</button><button id="admin-dmca" role="tab" aria-selected="false" aria-controls="admin-queue" tabindex="-1" data-admin-queue="dmca">版权申请</button><button id="admin-notifications" role="tab" aria-selected="false" aria-controls="admin-queue" tabindex="-1" data-admin-queue="notifications">站内通知</button></div>
      <div class="results-toolbar"><p id="admin-total" aria-live="polite"></p><label id="admin-status-field">状态<select id="admin-status"><option value="draft">待审核</option><option value="published">已公开</option><option value="disabled">已下架</option></select></label></div>
      <div class="admin-queue" id="admin-queue" role="tabpanel" aria-labelledby="admin-software" aria-live="polite" tabindex="0"></div>
      <nav class="pagination" id="admin-pagination" aria-label="管理队列分页"></nav>
    </section>

    <section class="page page-width" id="page-not-found" data-page="not-found"><div class="empty-state"><div class="empty-illustration" aria-hidden="true"><svg viewBox="0 0 180 130"><path d="M31 42h118v73H31z" fill="#eef0ff"/><path d="M31 42 48 20h84l17 22" fill="#fff" stroke="#adb4e9" stroke-width="4"/><path d="M72 77h36" stroke="#6d70d8" stroke-width="5" stroke-linecap="round"/><circle cx="67" cy="62" r="4" fill="#9199dc"/><circle cx="113" cy="62" r="4" fill="#9199dc"/></svg></div><h1>页面没有找到</h1><p>链接可能已更新，返回首页继续浏览。</p><a class="button button--primary" href="#/home">返回首页</a></div></section>
  </main>

  <footer class="site-footer"><div class="page-width footer-grid"><div><a class="brand brand--footer" href="#/home"><svg class="brand__mark"><use href="#i-logo"/></svg><span>数享社区</span></a><p>专注于数字软件与资源信息分享。下载与使用前，请确认你拥有相应权利并遵守软件许可。</p></div><div><strong>社区服务</strong><a href="#/search">软件资源库</a><a href="#/rankings">热门榜单</a><button data-modal="download-notice">下载须知</button><button id="pwa-cache-clear">清理离线缓存</button></div><div><strong>版权与合规</strong><button data-modal="dmca">侵权下架申请</button><button data-modal="agreement">用户协议</button><button data-modal="dead-link">失效链接举报</button></div></div><div class="page-width footer-bottom"><span>© <?= date('Y') ?> 数享社区</span><span>尊重版权 · 安全分享 · 理性下载</span></div></footer>

  <nav class="bottom-nav" aria-label="底部导航"><a href="#/home" data-route="home"><svg><use href="#i-home"/></svg><span>首页</span></a><a href="#/search" data-route="search"><svg><use href="#i-grid"/></svg><span>资源</span></a><a href="#/rankings" data-route="rankings"><svg><use href="#i-rank"/></svg><span>榜单</span></a><button id="bottom-profile"><svg><use href="#i-user"/></svg><span>我的</span></button></nav>
</div>

<dialog class="modal" id="search-modal"><div class="modal__panel modal__panel--search"><div class="modal__head"><div><span class="section-kicker">Quick search</span><h2>搜索资源</h2></div><button class="icon-button" data-dialog-close aria-label="关闭"><svg><use href="#i-close"/></svg></button></div><form id="global-search-form"><div class="input-with-icon input-with-icon--large"><svg><use href="#i-search"/></svg><input id="global-query" type="search" placeholder="输入软件名称、版本或平台" autocomplete="off" required><button class="button button--primary" type="submit">搜索</button></div></form><div class="search-suggestions"><span>热门搜索</span><button data-query="Android">Android</button><button data-query="效率工具">效率工具</button><button data-query="开源">开源</button><button data-query="图像处理">图像处理</button></div></div></dialog>

<dialog class="modal" id="agreement-modal"><form method="dialog" class="modal__panel"><div class="modal__head"><div><span class="section-kicker">Before you continue</span><h2>首次访问声明</h2></div></div><div class="legal-copy"><p>本站用于合法的软件信息交流与资源分享，不直接保证第三方资源的持续可用性。访问、下载或使用任何软件前，请确认来源、许可证和系统兼容性。</p><ul><li>尊重著作权、商标权及其他合法权益。</li><li>请独立校验公开的 MD5/SHA256，并使用可信安全工具检查文件。</li><li>禁止上传恶意程序、违法内容或无权分发的商业资源。</li><li>发现侵权或失效链接，可通过页面入口提交处理申请。</li></ul></div><label class="agreement-check"><input id="agreement-check" type="checkbox" required><span>我已阅读并同意遵守上述声明</span></label><button class="button button--primary button--block" id="agreement-accept" value="default" disabled>同意并进入</button></form></dialog>

<dialog class="modal" id="download-notice-modal"><div class="modal__panel modal__panel--sheet"><div class="modal__head"><div><span class="section-kicker">Download notice</span><h2>下载须知与免责声明</h2></div><button class="icon-button" data-dialog-close aria-label="关闭"><svg><use href="#i-close"/></svg></button></div><div class="notice-steps"><div><b>1</b><p><strong>核对版本</strong><span>确认平台、系统版本与硬件架构匹配。</span></p></div><div><b>2</b><p><strong>校验文件</strong><span>下载后比对页面展示的 MD5 或 SHA256。</span></p></div><div><b>3</b><p><strong>遵守许可</strong><span>第三方软件的使用受其作者许可协议约束。</span></p></div></div><button class="button button--primary button--block" data-dialog-close>我知道了</button></div></dialog>

<dialog class="modal" id="dmca-modal"><form class="modal__panel" id="dmca-form"><div class="modal__head"><div><span class="section-kicker">Copyright request</span><h2>侵权快捷处理 / 下架申请</h2></div><button class="icon-button" type="button" data-dialog-close aria-label="关闭"><svg><use href="#i-close"/></svg></button></div><div class="form-grid"><label><span>权利人姓名 / 机构</span><input name="name" required maxlength="100"></label><label><span>联系邮箱</span><input name="email" type="email" required maxlength="160"></label><label class="form-grid__full"><span>涉及资源链接</span><input name="url" type="url" required placeholder="https://"></label><label class="form-grid__full"><span>权利说明与处理请求</span><textarea name="statement" required minlength="20" maxlength="2000" rows="5"></textarea></label><label class="agreement-check form-grid__full"><input name="truthful" type="checkbox" required><span>我确认提交的信息真实，并愿意配合提供权属证明。</span></label></div><button class="button button--primary button--block" type="submit">提交下架申请</button></form></dialog>

<dialog class="modal" id="dead-link-modal"><form class="modal__panel" id="dead-link-form"><div class="modal__head"><div><span class="section-kicker">Link report</span><h2>失效链接举报</h2></div><button class="icon-button" type="button" data-dialog-close aria-label="关闭"><svg><use href="#i-close"/></svg></button></div><input type="hidden" name="software_id" id="report-software-id"><label><span>问题类型</span><select name="reason" required><option value="expired">链接已失效</option><option value="password">密码错误</option><option value="mismatch">文件与描述不符</option><option value="unsafe">疑似安全风险</option></select></label><label><span>补充说明（可选）</span><textarea name="details" rows="4" maxlength="480"></textarea></label><button class="button button--primary button--block" type="submit">提交举报</button></form></dialog>

<div class="toast-region" id="toast-region" aria-live="polite" aria-atomic="true"></div>
<div class="pwa-update" id="pwa-update" role="status" hidden><span>新版本已准备好，刷新后生效。</span><button class="button button--primary" id="pwa-update-refresh" type="button">立即刷新</button></div>
<script src="/assets/app.min.js?v=20260917-1" defer></script>
</body>
</html>
