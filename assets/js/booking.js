/* global weddingBookingData */
(function () {
  "use strict";

  /* -------------------------------------------------
     Config (localized as weddingBookingData by shortcode.php)
  ------------------------------------------------- */
  const D = window.weddingBookingData || {};
  const I18N = D.i18n || {};
  const LOC = D.locale || {};
  const MONEY = D.money || {};

  // Customer-facing text: the translated string from PHP, else English.
  function t(key, fallback) {
    const v = I18N[key];
    return typeof v === "string" && v !== "" ? v : fallback;
  }

  // "{name}" placeholders → values; unknown placeholders are left alone.
  function fill(str, vars) {
    return String(str).replace(/\{(\w+)\}/g, (m, k) =>
      Object.prototype.hasOwnProperty.call(vars, k) ? String(vars[k]) : m,
    );
  }

  function list(arr, len, fallback) {
    return Array.isArray(arr) && arr.length === len ? arr : fallback;
  }
  const MONTHS = list(LOC.months, 12, [
    "January", "February", "March", "April", "May", "June",
    "July", "August", "September", "October", "November", "December",
  ]);
  const MONTHS_SHORT = list(LOC.monthsShort, 12, MONTHS.map((m) => m.slice(0, 3)));
  const WEEKDAYS = list(LOC.weekdays, 7, [
    "Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday",
  ]);
  const WEEKDAYS_SHORT = list(LOC.weekdaysShort, 7, WEEKDAYS.map((d) => d.slice(0, 3)));

  /* -------------------------------------------------
     State
  ------------------------------------------------- */
  let sessions = [];
  let packages = [];
  let addons = [];
  let paymentGateways = [];

  // Live availability (wedding_booking_get_availability). Until it loads, no date
  // can be picked — a failed request must never read as "every day is free".
  let availability = {
    loaded: false,
    failed: false,
    unavailable: {}, // "YYYY-MM-DD" => "booked" | "blocked"
    rules: null, // today, minDate, maxDate, closedWeekdays, weekStart
    slots: { enabled: false, times: [], taken: {} },
  };
  // Dates/times the server refused since the page loaded (taken meanwhile);
  // kept across availability reloads.
  const localUnavailable = {};
  const localTaken = {};

  let activeSessionId = null;
  let selectedPkgId = null;
  let selectedPkg = null;
  let chosenAddons = [];
  let chosenDate = null;
  let chosenTime = ""; // "HH:MM" when the studio offers start times
  let couponCode = ""; // validated promo code ('' = none)

  // ?wedding_booking_package={slug-or-id} share link — resolved to a package row after the
  // booking data loads, applied once when the package grid first renders.
  // The param stays in the URL, so a refresh keeps the pre-selection.
  let preselectPkg = null;

  const checkoutMode = D.checkoutMode === "redirect" ? "redirect" : "direct";
  let partialPaymentEnabled = !!D.partialPaymentEnabled;
  let partialBlockDays = parseInt(D.partialBlockDays || 0, 10) || 0;
  let partialOptionLabel =
    D.partialOptionLabel || "Book your slot with a {deposit_pct}% deposit";
  let usePartialPayment = partialPaymentEnabled;

  // Server quote for the current selection (wedding_booking_preview_payment).
  let serverQuote = null; // { key, q }
  let previewSeq = 0;
  let previewTimer = null;

  // The Contract step (Wedding Booking → Booking Form) sits between Details and Payment
  // when it's on, so the wizard is 4 steps instead of 3. Every step number in
  // this file is derived from these constants.
  const contractEnabled = !!D.contractEnabled;
  const contractInfo = D.contract || {};
  const signatureRequired = contractEnabled && !!contractInfo.signature;
  const PAY_STEP = contractEnabled ? 4 : 3;
  const TOTAL_STEPS = PAY_STEP;

  let currentStep = 1;
  let bookingLocked = false; // set once an order is placed/confirmed
  let embedOrder = null; // { id, key, snapshot } — order behind the embedded payment
  let placeInFlight = false;
  let placeQueued = false;

  // Progress kept across reloads / a trip to the login page.
  let userTouched = false; // a real click/keypress since load
  let restoring = false;
  let progressReady = false; // saving starts once the stored copy was read

  /* -------------------------------------------------
     AJAX
  ------------------------------------------------- */
  function post(action, data) {
    const fd = new FormData();
    fd.append("action", action);
    fd.append("nonce", D.nonce || "");
    Object.entries(data || {}).forEach(([k, v]) =>
      fd.append(k, v === null || v === undefined ? "" : v),
    );
    return fetch(D.ajaxUrl, { method: "POST", body: fd })
      .then((r) => r.text())
      .then((text) => {
        // WordPress answers an expired security token with a bare "-1" (or
        // "0"), e.g. on a page served from a page cache. Say so instead of
        // failing silently.
        const tx = String(text).trim();
        if (tx === "-1" || tx === "0") {
          return {
            success: false,
            data: {
              message: t(
                "expired",
                "This page has expired. Please refresh the page and try again.",
              ),
            },
          };
        }
        return JSON.parse(text);
      });
  }

  // Ids of the chosen add-ons, as the server prices bookings from ids only.
  function addonIdsCsv() {
    return chosenAddons.map((a) => parseInt(a.id, 10)).join(",");
  }

  /* -------------------------------------------------
     Hold token — one random id per browser tab, so the
     customer's own unpaid order never blocks their date.
  ------------------------------------------------- */
  const HOLD_KEY = "wedding_booking_hold_token";
  let holdTokenCache = "";

  function randomToken(len) {
    const chars =
      "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";
    let out = "";
    const c = window.crypto;
    if (c && c.getRandomValues) {
      const buf = new Uint32Array(len);
      c.getRandomValues(buf);
      for (let i = 0; i < len; i++) out += chars[buf[i] % chars.length];
    } else {
      for (let i = 0; i < len; i++) {
        out += chars[Math.floor(Math.random() * chars.length)];
      }
    }
    return out;
  }

  function holdToken() {
    if (holdTokenCache) return holdTokenCache;
    let tok = "";
    try {
      tok = window.sessionStorage.getItem(HOLD_KEY) || "";
    } catch (_e) {
      tok = "";
    }
    if (!/^[A-Za-z0-9]{24}$/.test(tok)) {
      tok = randomToken(24);
      try {
        window.sessionStorage.setItem(HOLD_KEY, tok);
      } catch (_e) {
        /* private mode — the token still lives for this page */
      }
    }
    holdTokenCache = tok;
    return tok;
  }

  /* -------------------------------------------------
     Money, dates, times — in the shop's / site's format
  ------------------------------------------------- */
  function formatMoney(amount) {
    const n = Number(amount);
    const value = isFinite(n) ? n : 0;
    const dec = parseInt(MONEY.decimals, 10);
    const decimals = isNaN(dec) ? 2 : Math.max(0, Math.min(6, dec));
    const dsep = typeof MONEY.decimalSep === "string" ? MONEY.decimalSep : ".";
    const tsep =
      typeof MONEY.thousandSep === "string" ? MONEY.thousandSep : ",";
    const fixed = Math.abs(value).toFixed(decimals);
    const parts = fixed.split(".");
    const int = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, tsep);
    const num = parts.length > 1 ? int + dsep + parts[1] : int;
    const sym = typeof MONEY.symbol === "string" ? MONEY.symbol : "";
    const nb = " ";
    let out;
    switch (MONEY.position) {
      case "right":
        out = num + sym;
        break;
      case "left_space":
        out = sym + nb + num;
        break;
      case "right_space":
        out = num + nb + sym;
        break;
      default:
        out = sym + num;
    }
    return (value < 0 && Number(fixed) !== 0 ? "−" : "") + out;
  }

  function fmtPct(p) {
    return String(parseFloat(Number(p || 0).toFixed(2)));
  }

  function round2(n) {
    return Math.round((Number(n) || 0) * 100) / 100;
  }

  function pad2(n) {
    return String(n).padStart(2, "0");
  }

  function parseYmd(ds) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(ds || "");
    return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null;
  }

  function ymd(d) {
    return d.getFullYear() + "-" + pad2(d.getMonth() + 1) + "-" + pad2(d.getDate());
  }

  // PHP date() subset used by WordPress date formats, with the site's
  // month/weekday names.
  function phpDate(fmt, d) {
    let out = "";
    for (let i = 0; i < fmt.length; i++) {
      const c = fmt[i];
      if (c === "\\") {
        i++;
        if (i < fmt.length) out += fmt[i];
        continue;
      }
      const day = d.getDate();
      switch (c) {
        case "d": out += pad2(day); break;
        case "j": out += day; break;
        case "S":
          out +=
            day % 10 === 1 && day !== 11 ? "st"
              : day % 10 === 2 && day !== 12 ? "nd"
                : day % 10 === 3 && day !== 13 ? "rd"
                  : "th";
          break;
        case "l": out += WEEKDAYS[d.getDay()]; break;
        case "D": out += WEEKDAYS_SHORT[d.getDay()]; break;
        case "N": out += d.getDay() || 7; break;
        case "w": out += d.getDay(); break;
        case "F": out += MONTHS[d.getMonth()]; break;
        case "M": out += MONTHS_SHORT[d.getMonth()]; break;
        case "m": out += pad2(d.getMonth() + 1); break;
        case "n": out += d.getMonth() + 1; break;
        case "Y": out += d.getFullYear(); break;
        case "y": out += String(d.getFullYear()).slice(-2); break;
        default: out += c;
      }
    }
    return out;
  }

  function formatDate(ds, withWeekday) {
    const d = parseYmd(ds);
    if (!d) return ds || "—";
    const fmt = LOC.dateFormat || "F j, Y";
    let out = phpDate(fmt, d);
    if (withWeekday && !/(^|[^\\])[lD]/.test(fmt)) {
      out = WEEKDAYS[d.getDay()] + ", " + out;
    }
    return out;
  }

  function formatTime(hm) {
    const m = /^(\d{1,2}):(\d{2})$/.exec(hm || "");
    if (!m) return hm || "";
    const h = parseInt(m[1], 10);
    const fmt = LOC.timeFormat || "g:i a";
    let out = "";
    for (let i = 0; i < fmt.length; i++) {
      const c = fmt[i];
      if (c === "\\") {
        i++;
        if (i < fmt.length) out += fmt[i];
        continue;
      }
      switch (c) {
        case "g": out += h % 12 || 12; break;
        case "G": out += h; break;
        case "h": out += pad2(h % 12 || 12); break;
        case "H": out += pad2(h); break;
        case "i": out += m[2]; break;
        case "a": out += h < 12 ? LOC.am || "am" : LOC.pm || "pm"; break;
        case "A": out += h < 12 ? LOC.AM || "AM" : LOC.PM || "PM"; break;
        default: out += c;
      }
    }
    return out;
  }

  function prefersReducedMotion() {
    return !!(
      window.matchMedia &&
      window.matchMedia("(prefers-reduced-motion: reduce)").matches
    );
  }

  function scrollBehavior() {
    return prefersReducedMotion() ? "auto" : "smooth";
  }

  /* -------------------------------------------------
     Shareable package deep-link (?wedding_booking_package=slug-or-id)
  ------------------------------------------------- */
  function getUrlPackageParam() {
    // A pre-selected package can come from the shortcode's package
    // attribute (data-package on the wrapper) or a ?wedding_booking_package= share link.
    // The explicit shortcode choice wins over the URL.
    try {
      const wrap = document.querySelector(".wedding-booking-wrap[data-package]");
      const fromWrap = wrap
        ? (wrap.getAttribute("data-package") || "").trim()
        : "";
      if (fromWrap) return fromWrap;
    } catch (_e) {
      /* fall through to URL */
    }
    try {
      return (
        new URLSearchParams(window.location.search).get("wedding_booking_package") || ""
      ).trim();
    } catch (_e) {
      return "";
    }
  }

  // Resolve the ?wedding_booking_package= value against the loaded data. Only active
  // packages (under an active session type) are returned by the server,
  // so anything unknown here is unavailable → show the notice.
  function resolvePreselectPackage() {
    const param = getUrlPackageParam();
    if (!param) return;

    const wanted = param.toLowerCase();
    const found =
      packages.find((p) => String(p.slug || "").toLowerCase() === wanted) ||
      packages.find((p) => String(p.id) === wanted);
    const sessionOk =
      found &&
      sessions.some(
        (s) => parseInt(s.id, 10) === parseInt(found.session_id, 10),
      );

    if (found && sessionOk) {
      preselectPkg = found;
    } else {
      const notice = byId("wedding-booking-pkgNotice");
      if (notice) notice.style.display = "";
    }
  }

  /* -------------------------------------------------
     Loading skeletons
  ------------------------------------------------- */
  // Placeholder shapes shown while data is in flight, so the form has its
  // final dimensions from the first paint and nothing jumps when data lands.
  // Disabled from the admin via Frontend → "Loading placeholders".
  // The placeholders are injected as direct children of the existing grids
  // (#wedding-booking-calGrid, #wedding-booking-pkgGrid), so they inherit those grid tracks. Wrapping
  // them in a container of their own would nest a grid inside a grid and
  // collapse the whole skeleton into the first column.
  function skeletonHtml(kind) {
    if (kind === "calendar") {
      return '<span class="wedding-booking-skel wedding-booking-skel-cell" aria-hidden="true"></span>'.repeat(
        35,
      );
    }
    return (
      '<div class="wedding-booking-skel-card" aria-hidden="true">' +
      '<span class="wedding-booking-skel wedding-booking-skel-line wedding-booking-skel-w60"></span>' +
      '<span class="wedding-booking-skel wedding-booking-skel-line wedding-booking-skel-w40"></span>' +
      '<span class="wedding-booking-skel wedding-booking-skel-line"></span>' +
      "</div>"
    ).repeat(3);
  }

  function showSkeleton(kind) {
    if (!D.showLoader) return;
    const target = byId(kind === "calendar" ? "wedding-booking-calGrid" : "wedding-booking-pkgGrid");
    if (!target || target.dataset.weddingBookingSkel === "1") return;
    target.dataset.weddingBookingSkel = "1";
    target.setAttribute("aria-busy", "true");
    target.innerHTML = skeletonHtml(kind);
  }

  function clearSkeleton() {
    ["wedding-booking-calGrid", "wedding-booking-pkgGrid"].forEach((id) => {
      const el = byId(id);
      if (!el || el.dataset.weddingBookingSkel !== "1") return;
      delete el.dataset.weddingBookingSkel;
      el.removeAttribute("aria-busy");
      el.innerHTML = "";
    });
  }

  /* -------------------------------------------------
     Init
  ------------------------------------------------- */
  // The catalog ships inline with the page, so packages paint immediately.
  // Only availability needs a live request (a cached page must never offer
  // a date that has since been booked).
  function init() {
    const inline = D.catalog;
    const catalogReady = !!(inline && inline.packages);

    if (catalogReady) {
      applyCatalog(inline);
      resolvePreselectPackage();
      renderSessionTabs();
      updatePkgNextState();
    } else {
      showSkeleton("packages");
    }
    showSkeleton("calendar");
    renderWeekdayHeader();
    renderSlots();

    const catalogReq = catalogReady
      ? Promise.resolve(null)
      : post("wedding_booking_get_data", {});

    Promise.all([catalogReq, loadAvailability()])
      .then((responses) => {
        const catRes = responses[0];
        // Clear placeholders before rendering, or the render would be
        // overwritten by the teardown.
        clearSkeleton();
        if (!catalogReady) {
          if (catRes && catRes.success) {
            applyCatalog(catRes.data);
            resolvePreselectPackage();
            renderSessionTabs();
            updatePkgNextState();
          } else {
            showCatalogError();
          }
        }
        initCalendar();
        if (availability.loaded) restoreProgress();
      })
      .catch(() => {
        clearSkeleton();
        showCatalogError();
        initCalendar();
      });
  }

  function showCatalogError() {
    const el = byId("wedding-booking-typeTabs");
    if (el) {
      el.innerHTML =
        '<p class="wedding-booking-load-error" role="alert">' +
        escHtml(t("loadError", "Could not load booking data. Please refresh.")) +
        "</p>";
    }
  }

  // Copy a catalog payload (inline or AJAX — same shape) into module state.
  function applyCatalog(data) {
    sessions = data.sessions || [];
    packages = data.packages || [];
    addons = data.addons || [];
    if (typeof data.partialPaymentEnabled !== "undefined") {
      partialPaymentEnabled = !!data.partialPaymentEnabled;
      partialBlockDays = parseInt(data.partialBlockDays || 0, 10) || 0;
      partialOptionLabel = data.partialOptionLabel || partialOptionLabel;
      if (!partialPaymentEnabled) usePartialPayment = false;
    }
  }

  /* -------------------------------------------------
     Availability
  ------------------------------------------------- */
  function loadAvailability() {
    return post("wedding_booking_get_availability", { hold_token: holdToken() })
      .then((res) => {
        if (res && res.success && res.data) applyAvailability(res.data);
        else availabilityFailed();
      })
      .catch(() => availabilityFailed());
  }

  function applyAvailability(data) {
    // PHP sends an empty map as [], so normalise.
    const obj = (v) => (v && typeof v === "object" && !Array.isArray(v) ? v : {});
    const rules = obj(data.rules);
    const slots = obj(data.slots);
    const times = Array.isArray(slots.times)
      ? slots.times.map(String).filter((x) => /^\d{2}:\d{2}$/.test(x))
      : [];
    const taken = {};
    Object.entries(obj(slots.taken)).forEach(([ds, arr]) => {
      taken[ds] = Array.isArray(arr) ? arr.map(String) : [];
    });
    Object.entries(localTaken).forEach(([ds, arr]) => {
      taken[ds] = (taken[ds] || []).concat(arr);
    });

    availability = {
      loaded: true,
      failed: false,
      unavailable: Object.assign({}, obj(data.unavailable), localUnavailable),
      rules: {
        today: String(rules.today || ymd(new Date())),
        minDate: String(rules.minDate || ""),
        maxDate: String(rules.maxDate || ""),
        closedWeekdays: (Array.isArray(rules.closedWeekdays)
          ? rules.closedWeekdays
          : []
        )
          .map((n) => parseInt(n, 10))
          .filter((n) => n >= 0 && n <= 6),
        weekStart: parseInt(rules.weekStart, 10) || 0,
      },
      slots: { enabled: !!slots.enabled && times.length > 0, times, taken },
    };
    hideCalErr();
    renderWeekdayHeader();
  }

  function availabilityFailed() {
    availability.loaded = false;
    availability.failed = true;
    showCalErr(t("availabilityError", "Availability couldn't be loaded."), true);
  }

  function retryAvailability() {
    hideCalErr();
    showSkeleton("calendar");
    loadAvailability().then(() => {
      clearSkeleton();
      if (availability.loaded) {
        startMonth();
        renderCalendar();
        renderSlots();
        restoreProgress();
      } else {
        renderCalendar();
      }
    });
  }

  function slotsOn() {
    return availability.loaded
      ? !!availability.slots.enabled
      : !!D.slotsEnabled;
  }

  function todayStr() {
    return (availability.rules && availability.rules.today) || ymd(new Date());
  }

  function takenTimes(ds) {
    const v = availability.slots.taken[ds];
    return Array.isArray(v) ? v : [];
  }

  function freeTimes(ds) {
    const taken = takenTimes(ds);
    return availability.slots.times.filter((x) => taken.indexOf(x) === -1);
  }

  // '' when the date can be booked, else why not.
  function dateStatus(ds) {
    const r = availability.rules || {};
    if (ds < todayStr()) return "past";
    if (r.minDate && ds < r.minDate) return "notice";
    if (r.maxDate && ds > r.maxDate) return "window";
    const d = parseYmd(ds);
    if (d && (r.closedWeekdays || []).indexOf(d.getDay()) !== -1) return "closed";
    if (!availability.loaded) return "unknown";
    const u = availability.unavailable[ds];
    if (u) return String(u);
    if (slotsOn() && freeTimes(ds).length === 0) return "full";
    return "";
  }

  /* -------------------------------------------------
     Payment gateways (fallback list / redirect mode)
  ------------------------------------------------- */
  // Payment gateways are only needed on the payment step, so they are
  // fetched when the customer commits to a package rather than on page
  // load — one less request blocking the first render.
  let gatewaysPromise = null;
  let gatewaysLoaded = false;
  function prefetchGateways() {
    if (gatewaysPromise || !D.hasWC) return gatewaysPromise;
    gatewaysPromise = post("wedding_booking_get_payment_gateways", {})
      .then((res) => {
        if (res && res.success) paymentGateways = res.data.gateways || [];
        gatewaysLoaded = true;
      })
      .catch(() => {
        // Settled either way: the payment step falls back to the embedded
        // checkout, and the list must not sit on "loading" forever.
        gatewaysLoaded = true;
      });
    return gatewaysPromise;
  }

  /* -------------------------------------------------
     Session types
  ------------------------------------------------- */
  function renderSessionTabs() {
    const wrap = byId("wedding-booking-typeTabs");
    if (!wrap) return;
    if (!sessions.length) {
      wrap.innerHTML =
        '<span class="wedding-booking-stype-loading">' +
        escHtml(t("noSessions", "No session types configured.")) +
        "</span>";
      return;
    }

    wrap.innerHTML = sessions
      .map(
        (s) =>
          '<button class="wedding-booking-stype-btn" data-id="' +
          parseInt(s.id, 10) +
          '" type="button" aria-pressed="false">' +
          (s.emoji
            ? '<span class="wedding-booking-stype-em" aria-hidden="true">' +
              iconHtml(s.emoji) +
              "</span>"
            : "") +
          '<span class="wedding-booking-stype-name">' +
          escHtml(s.name) +
          "</span>" +
          "</button>",
      )
      .join("");

    wrap.querySelectorAll(".wedding-booking-stype-btn").forEach((btn) => {
      btn.addEventListener("click", (e) => {
        if (e.isTrusted) userTouched = true;
        activateSession(btn);
      });
    });

    // Auto-select first — or the session type owning the ?wedding_booking_package= link
    let startBtn = wrap.querySelector(".wedding-booking-stype-btn");
    if (preselectPkg) {
      const target = wrap.querySelector(
        '.wedding-booking-stype-btn[data-id="' + parseInt(preselectPkg.session_id, 10) + '"]',
      );
      if (target) startBtn = target;
    }
    if (startBtn) activateSession(startBtn);
  }

  function activateSession(btn) {
    const wrap = byId("wedding-booking-typeTabs");
    if (!wrap) return;
    wrap.querySelectorAll(".wedding-booking-stype-btn").forEach((b) => {
      b.classList.remove("wedding-booking-act");
      b.setAttribute("aria-pressed", "false");
    });
    btn.classList.add("wedding-booking-act");
    btn.setAttribute("aria-pressed", "true");
    activeSessionId = parseInt(btn.dataset.id, 10);
    selectedPkgId = null;
    selectedPkg = null;
    showAddons(false);
    renderPackages();
    renderPartialPaymentOption();
    onSelectionChanged();
  }

  /* -------------------------------------------------
     Package cards
  ------------------------------------------------- */
  function renderPackages() {
    const grid = byId("wedding-booking-pkgGrid");
    if (!grid) return;
    const pkgs = packages.filter(
      (p) => parseInt(p.session_id, 10) === activeSessionId,
    );
    if (!pkgs.length) {
      grid.innerHTML =
        '<p class="wedding-booking-no-pkgs">' +
        escHtml(t("noPackages", "No packages for this session type yet.")) +
        "</p>";
      return;
    }
    grid.innerHTML = pkgs
      .map(
        (p) =>
          '<div class="wedding-booking-pkg' +
          (p.featured == "1" ? " wedding-booking-feat" : "") +
          '" data-id="' +
          parseInt(p.id, 10) +
          '" role="button" tabindex="0" aria-pressed="false">' +
          (p.featured == "1"
            ? '<span class="wedding-booking-pkg-tag">&#9733; ' +
              escHtml(t("popular", "Popular")) +
              "</span>"
            : "") +
          '<div class="wedding-booking-pkg-name">' +
          escHtml(p.name) +
          "</div>" +
          '<div class="wedding-booking-pkg-price">' +
          escHtml(formatMoney(p.price)) +
          "</div>" +
          (p.duration
            ? '<div class="wedding-booking-pkg-dur">' + escHtml(p.duration) + "</div>"
            : "") +
          // Description is rich text sanitized server-side (wp_kses_post)
          (p.description
            ? '<div class="wedding-booking-pkg-desc">' + p.description + "</div>"
            : "") +
          "</div>",
      )
      .join("");
    grid.querySelectorAll(".wedding-booking-pkg").forEach((card) => {
      card.addEventListener("click", (e) => {
        if (e.isTrusted) userTouched = true;
        selectCard(card);
      });
      card.addEventListener("keydown", (e) => {
        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          userTouched = true;
          selectCard(card);
        }
      });
    });

    // Apply the ?wedding_booking_package= pre-selection once. Goes through the same path
    // as a manual choice, so add-ons render visible but unchecked.
    if (
      preselectPkg &&
      parseInt(preselectPkg.session_id, 10) === activeSessionId
    ) {
      const card = grid.querySelector(
        '.wedding-booking-pkg[data-id="' + parseInt(preselectPkg.id, 10) + '"]',
      );
      preselectPkg = null;
      if (card) selectCard(card);
    }
  }

  function selectCard(card) {
    const grid = byId("wedding-booking-pkgGrid");
    if (!grid) return;
    grid.querySelectorAll(".wedding-booking-pkg").forEach((c) => {
      c.classList.remove("wedding-booking-sel");
      c.setAttribute("aria-pressed", "false");
    });
    card.classList.add("wedding-booking-sel");
    card.setAttribute("aria-pressed", "true");
    selectedPkgId = parseInt(card.dataset.id, 10);
    selectedPkg =
      packages.find((p) => parseInt(p.id, 10) === selectedPkgId) || null;
    clearErr("wedding-booking-s1err");
    renderAddons();
    renderPartialPaymentOption();
    onSelectionChanged();
  }

  /* -------------------------------------------------
     Add-ons
  ------------------------------------------------- */
  function addonPackageIds(a) {
    const csv = String(a.package_ids || "").trim();
    if (csv) {
      return csv
        .split(",")
        .map((x) => parseInt(x, 10))
        .filter((x) => x > 0);
    }
    const single = parseInt(a.package_id, 10) || 0; // legacy rows
    return single > 0 ? [single] : [];
  }

  function renderAddons() {
    const grid = byId("wedding-booking-addonsGrid");
    const wrap = byId("wedding-booking-addonsWrap");
    if (!grid) return;

    // Show add-ons that are global (no package list) OR assigned to the
    // selected package. package_ids is a CSV; package_id covers legacy rows.
    const visible = addons.filter((a) => {
      const ids = addonPackageIds(a);
      return !ids.length || ids.indexOf(selectedPkgId) !== -1;
    });

    if (!visible.length) {
      if (wrap) wrap.style.display = "none";
      grid.innerHTML = "";
      return;
    }

    grid.innerHTML = visible
      .map((a) => {
        const id = parseInt(a.id, 10);
        const pkgOnly = addonPackageIds(a).length > 0;
        const icon = iconHtml(a.emoji);
        return (
          '<label class="wedding-booking-addon-card" for="wedding-booking-addon-' +
          id +
          '">' +
          '<input class="wedding-booking-ac" type="checkbox" value="' +
          id +
          '" id="wedding-booking-addon-' +
          id +
          '">' +
          (icon
            ? '<span class="wedding-booking-addon-em" aria-hidden="true">' + icon + "</span>"
            : "") +
          '<span class="wedding-booking-addon-info">' +
          '<span class="wedding-booking-addon-name">' +
          escHtml(a.name) +
          "</span>" +
          // Description is rich text sanitized server-side (wp_kses_post)
          (a.description
            ? '<span class="wedding-booking-addon-desc">' + a.description + "</span>"
            : "") +
          (pkgOnly
            ? '<span class="wedding-booking-addon-badge">' +
              escHtml(t("packageOnly", "This package only")) +
              "</span>"
            : "") +
          "</span>" +
          '<span class="wedding-booking-addon-price">+' +
          escHtml(formatMoney(a.price)) +
          "</span>" +
          "</label>"
        );
      })
      .join("");

    grid.querySelectorAll(".wedding-booking-ac").forEach((cb) => {
      cb.addEventListener("change", (e) => {
        if (e.isTrusted) userTouched = true;
        onSelectionChanged();
      });
    });

    if (wrap) wrap.style.display = "";
  }

  function collectAddons() {
    chosenAddons = [];
    document
      .querySelectorAll("#wedding-booking-addonsGrid .wedding-booking-ac:checked")
      .forEach((cb) => {
        const a = addons.find((x) => String(x.id) === cb.value);
        if (a) chosenAddons.push(a);
      });
  }

  function showAddons(show) {
    // show/hide is now managed by renderAddons; only reset checkboxes on hide
    if (!show) {
      const w = byId("wedding-booking-addonsWrap");
      if (w) w.style.display = "none";
      document
        .querySelectorAll("#wedding-booking-addonsGrid input")
        .forEach((cb) => (cb.checked = false));
      chosenAddons = [];
    }
  }

  // Anything that changes the price or the selection.
  function onSelectionChanged() {
    collectAddons();
    renderQuote(currentQuote());
    updatePkgNextState();
    schedulePreview();
    saveProgress();
    updateLoginLinks();
  }

  /* -------------------------------------------------
     Deposit
  ------------------------------------------------- */
  // A package's own deposit % (catalog) when set, else the global setting.
  function pkgDepositPct(pkg) {
    const own = pkg ? parseInt(pkg.deposit_pct, 10) || 0 : 0;
    if (own > 0 && own < 100) return own;
    const global = parseInt(D.depositPct, 10) || 50;
    return Math.max(1, Math.min(99, global));
  }

  function daysUntil(ds) {
    const a = parseYmd(todayStr());
    const b = parseYmd(ds);
    if (!a || !b) return null;
    return Math.round(
      (Date.UTC(b.getFullYear(), b.getMonth(), b.getDate()) -
        Date.UTC(a.getFullYear(), a.getMonth(), a.getDate())) /
        86400000,
    );
  }

  function canUsePartialForSelectedDate() {
    if (!partialPaymentEnabled) return false;
    if (!chosenDate) return false;
    if (partialBlockDays <= 0) return true;
    const days = daysUntil(chosenDate);
    return days !== null && days >= partialBlockDays;
  }

  // 1 when the customer pays a deposit now (the server then charges the
  // package's deposit %), else 0.
  function useDepositFlag() {
    return partialPaymentEnabled &&
      usePartialPayment &&
      canUsePartialForSelectedDate()
      ? 1
      : 0;
  }

  function getEffectiveDepositPct() {
    return useDepositFlag() ? pkgDepositPct(selectedPkg) : 100;
  }

  function partialNoteText(on) {
    const pct = fmtPct(pkgDepositPct(selectedPkg));
    return on
      ? fill(t("partialOn", "Pay {pct}% now and settle the rest later."), { pct })
      : fill(
          t(
            "partialOff",
            "You'll pay the full amount now. Switch on to pay a {pct}% deposit instead.",
          ),
          { pct },
        );
  }

  function initPartialPaymentOption() {
    const toggle = byId("wedding-booking-partialToggle");
    if (!toggle) return;

    toggle.addEventListener("change", (e) => {
      if (e.isTrusted) userTouched = true;
      toggle.dataset.touched = "1";
      usePartialPayment = toggle.checked;
      setTxt("wedding-booking-partialNote", partialNoteText(toggle.checked));
      onSelectionChanged();
      if (onPayStep()) updateSummary();
    });
  }

  function renderPartialPaymentOption() {
    const wrap = byId("wedding-booking-partialWrap");
    const label = byId("wedding-booking-partialLabel");
    const note = byId("wedding-booking-partialNote");
    const toggle = byId("wedding-booking-partialToggle");
    if (!wrap || !label || !note || !toggle) return;

    if (!partialPaymentEnabled) {
      wrap.style.display = "none";
      usePartialPayment = false;
      return;
    }

    const pct = pkgDepositPct(selectedPkg);
    label.textContent = fill(partialOptionLabel, { deposit_pct: pct });
    setTxt("wedding-booking-partialEm", pct + "%");
    wrap.style.display = "none";

    if (!chosenDate) {
      toggle.checked = false;
      toggle.disabled = true;
      usePartialPayment = false;
      note.textContent = "";
      return;
    }

    if (canUsePartialForSelectedDate()) {
      wrap.style.display = "";
      toggle.disabled = false;
      if (!toggle.dataset.touched) {
        toggle.checked = true;
      }
      usePartialPayment = toggle.checked;
      note.textContent = partialNoteText(toggle.checked);
      return;
    }

    wrap.style.display = "none";
    toggle.checked = false;
    toggle.disabled = true;
    usePartialPayment = false;
    note.textContent = "";
  }

  /* -------------------------------------------------
     Pricing — local estimate first, then the server's
     quote (wedding_booking_preview_payment), which is what the
     order will actually charge.
  ------------------------------------------------- */
  function feePctLocal() {
    if (!D.hasWC) return 0;
    const p = parseFloat(D.paymentFeePct || 0);
    return isNaN(p) || p <= 0 ? 0 : Math.min(100, p);
  }

  function feeLabelDefault() {
    return D.paymentFeeLabel || t("paymentFee", "Payment fee");
  }

  function selectionBase() {
    return [
      selectedPkg ? parseInt(selectedPkg.id, 10) : 0,
      addonIdsCsv(),
      chosenDate || "",
      useDepositFlag(),
    ].join("|");
  }

  function localQuote() {
    const subtotal = round2(
      (selectedPkg ? parseFloat(selectedPkg.price || 0) : 0) +
        chosenAddons.reduce((s, a) => s + parseFloat(a.price || 0), 0),
    );
    const feePct = feePctLocal();
    const fee = round2((subtotal * feePct) / 100);
    const payable = round2(subtotal + fee);
    const payPct = payable > 0 ? getEffectiveDepositPct() : 100;
    const dueNow = round2((payable * payPct) / 100);
    return {
      subtotal,
      discount: 0,
      couponCode: "",
      total: subtotal,
      feePct,
      feeLabel: feeLabelDefault(),
      feeAmount: fee,
      payable,
      payPct,
      depositPct: pkgDepositPct(selectedPkg),
      dueNow,
      balance: Math.max(0, round2(payable - dueNow)),
      balanceDueDate: "",
      server: false,
    };
  }

  function quoteFromPreview(d) {
    const num = (v) => parseFloat(v || 0) || 0;
    const payable = num(d.payable || d.total);
    return {
      subtotal: num(d.subtotal !== undefined ? d.subtotal : d.total),
      discount: num(d.discount),
      couponCode: String(d.couponCode || ""),
      total: num(d.total),
      feePct: num(d.feePct),
      feeLabel: d.feeLabel || feeLabelDefault(),
      feeAmount: num(d.feeAmount),
      payable,
      payPct: parseInt(d.payPct || 100, 10) || 100,
      depositPct: parseInt(d.depositPct || 0, 10) || pkgDepositPct(selectedPkg),
      dueNow: num(d.dueToday),
      balance: num(d.balanceDue),
      balanceDueDate: String(d.balanceDueDate || ""),
      server: true,
    };
  }

  function currentQuote() {
    if (serverQuote && serverQuote.key === selectionBase() + "|" + couponCode) {
      return serverQuote.q;
    }
    return localQuote();
  }

  function schedulePreview(delay) {
    if (!D.hasWC || !selectedPkg) return;
    clearTimeout(previewTimer);
    previewTimer = setTimeout(
      () => {
        runPreview(couponCode).catch(() => {
          /* keep the local estimate */
        });
      },
      delay === undefined ? 200 : delay,
    );
  }

  // Resolves with the raw response (the promo code form reads couponError).
  function runPreview(code) {
    if (!selectedPkg) return Promise.resolve(null);
    const base = selectionBase();
    const seq = ++previewSeq;
    const data = {
      package_id: parseInt(selectedPkg.id, 10),
      addon_ids: addonIdsCsv(),
      session_date: chosenDate || "",
      use_deposit: useDepositFlag(),
      coupon_code: code || "",
      total_raw: localQuote().subtotal,
    };
    const email = detailValue("email");
    if (code && email) data["details[email]"] = email;

    return post("wedding_booking_preview_payment", data).then((res) => {
      if (res && res.success && res.data) {
        const q = quoteFromPreview(res.data);
        // An invalid code is priced without it: key on what was priced.
        const key = base + "|" + (res.data.couponCode ? q.couponCode : "");
        if (seq === previewSeq) {
          serverQuote = { key, q };
          renderQuote(currentQuote());
        }
      }
      return res;
    });
  }

  function priceNoteText(q) {
    const parts = [];
    if (q.discount > 0 && q.couponCode) {
      parts.push(
        fill(t("promoIncluded", "Promo code {code} applied: {amount}"), {
          code: q.couponCode.toUpperCase(),
          amount: "−" + formatMoney(q.discount),
        }),
      );
    }
    if (q.feePct > 0) {
      let s = fill(t("feeIncluded", "{label} of {pct}% included"), {
        label: q.feeLabel || feeLabelDefault(),
        pct: fmtPct(q.feePct),
      });
      if (D.feeExemptMethods) {
        s +=
          " " +
          fill(t("feeExempt", "(no fee for {methods})"), {
            methods: D.feeExemptMethods,
          });
      }
      parts.push(s);
    }
    return parts.join(" · ");
  }

  // Paint a quote into the Package-step price strip and the payment summary.
  function renderQuote(q) {
    const strip = byId("wedding-booking-s2Price");
    if (!selectedPkg) {
      if (strip) strip.style.display = "none";
      setNote("wedding-booking-s2PriceNote", "");
      return;
    }

    // Package step — the total already includes the payment fee, so the
    // amount doesn't jump on the payment step.
    if (strip) {
      setTxt("wedding-booking-s2Total", formatMoney(q.payable));
      setTxt(
        "wedding-booking-s2DueLabel",
        q.payPct < 100
          ? fill(t("payNowPct", "Pay now ({pct}%)"), { pct: fmtPct(q.payPct) })
          : t("payNow", "Pay now"),
      );
      setTxt("wedding-booking-s2Due", formatMoney(q.dueNow));
      const laterCell = byId("wedding-booking-s2LaterCell");
      if (laterCell) laterCell.style.display = q.balance > 0.01 ? "" : "none";
      setTxt("wedding-booking-s2Later", formatMoney(q.balance));
      strip.style.display = "";
    }
    setNote("wedding-booking-s2PriceNote", priceNoteText(q));

    // Payment step summary.
    const showFee = q.feePct > 0;
    const showDiscount = q.discount > 0 && !!q.couponCode;
    setTxt("wedding-booking-sum-price", formatMoney(q.subtotal));
    const priceLabel = byId("wedding-booking-sum-price-label");
    if (priceLabel) {
      if (!priceLabel.dataset.orig) {
        priceLabel.dataset.orig = priceLabel.textContent;
      }
      // One total only: with a fee or discount the first row becomes
      // "Subtotal" and "Total payable" is the single total.
      priceLabel.textContent =
        showFee || showDiscount
          ? D.subtotalLabel || "Subtotal"
          : priceLabel.dataset.orig;
    }
    const discRow = byId("wedding-booking-sum-discount-row");
    if (discRow) {
      discRow.style.display = showDiscount ? "" : "none";
      if (showDiscount) {
        setTxt("wedding-booking-sum-discount-code", q.couponCode.toUpperCase());
        setTxt("wedding-booking-sum-discount", "−" + formatMoney(q.discount));
      }
    }
    const feeRow = byId("wedding-booking-sum-fee-row");
    if (feeRow) feeRow.style.display = showFee ? "" : "none";
    if (showFee) {
      setTxt(
        "wedding-booking-sum-fee-label",
        (q.feeLabel || feeLabelDefault()) + " (" + fmtPct(q.feePct) + "%)",
      );
      setTxt("wedding-booking-sum-fee", "+" + formatMoney(q.feeAmount));
    }
    const payRow = byId("wedding-booking-sum-payable-row");
    if (payRow) payRow.style.display = showFee || showDiscount ? "" : "none";
    setTxt("wedding-booking-sum-payable", formatMoney(q.payable));
    setTxt("wedding-booking-sum-total", formatMoney(q.dueNow));
    setTxt(
      "wedding-booking-sum-dep",
      q.payPct < 100
        ? fill(t("depositLabel", "{pct}% booking deposit"), {
            pct: fmtPct(q.payPct),
          })
        : t("fullPayment", "Full payment"),
    );

    const balRow = byId("wedding-booking-sum-balance-row");
    if (balRow) {
      if (q.balance > 0.01) {
        const tpl = q.balanceDueDate
          ? t(
              "balanceNoteDate",
              "Remaining balance {amount} is due by {date} — we will send you a payment link.",
            )
          : t(
              "balanceNote",
              "Remaining balance {amount} is due later — we will send you a payment link.",
            );
        balRow.innerHTML = fill(escHtml(tpl), {
          amount:
            '<strong id="wedding-booking-sum-balance">' +
            escHtml(formatMoney(q.balance)) +
            "</strong>",
          date: escHtml(formatDate(q.balanceDueDate, false)),
        });
        balRow.style.display = "";
      } else {
        balRow.style.display = "none";
      }
    }

    const feeNote = byId("wedding-booking-sum-fee-note");
    if (feeNote) {
      if (showFee && D.feeExemptMethods) {
        feeNote.textContent = fill(
          t("feeExemptPay", "{label}: not charged when you pay by {methods}."),
          { label: q.feeLabel || feeLabelDefault(), methods: D.feeExemptMethods },
        );
        feeNote.style.display = "";
      } else {
        feeNote.style.display = "none";
      }
    }
    renderPromoUi();
  }

  /* -------------------------------------------------
     Calendar
  ------------------------------------------------- */
  let calDate = new Date();
  calDate.setDate(1);
  let calendarBound = false;

  function weekStartDay() {
    if (availability.rules) return availability.rules.weekStart;
    return parseInt(LOC.weekStart, 10) || 0;
  }

  function renderWeekdayHeader() {
    const el = byId("wedding-booking-calDays");
    if (!el) return;
    const ws = weekStartDay();
    let html = "";
    for (let i = 0; i < 7; i++) {
      const wd = (ws + i) % 7;
      html +=
        '<span title="' +
        escHtml(WEEKDAYS[wd]) +
        '">' +
        escHtml(WEEKDAYS_SHORT[wd]) +
        "</span>";
    }
    el.innerHTML = html;
  }

  // Open on the month of the chosen date, else the first bookable month.
  function startMonth() {
    const r = availability.rules || {};
    const from = parseYmd(chosenDate || "") || parseYmd(r.minDate || "") || parseYmd(todayStr());
    if (from) calDate = new Date(from.getFullYear(), from.getMonth(), 1);
  }

  function initCalendar() {
    if (!calendarBound) {
      calendarBound = true;
      byId("wedding-booking-calPrev")?.addEventListener("click", () => {
        calDate.setMonth(calDate.getMonth() - 1);
        renderCalendar();
      });
      byId("wedding-booking-calNext")?.addEventListener("click", () => {
        calDate.setMonth(calDate.getMonth() + 1);
        renderCalendar();
      });
      const grid = byId("wedding-booking-calGrid");
      if (grid) {
        grid.addEventListener("click", (e) => {
          const cell = e.target.closest(".wedding-booking-cell[data-date]");
          if (!cell || cell.getAttribute("aria-disabled") === "true") return;
          chooseDate(cell.dataset.date, true);
        });
        grid.addEventListener("keydown", (e) => {
          const cell = e.target.closest(".wedding-booking-cell[data-date]");
          if (!cell) return;
          if (e.key === "Enter" || e.key === " ") {
            e.preventDefault();
            if (cell.getAttribute("aria-disabled") !== "true") {
              chooseDate(cell.dataset.date, true);
            }
          }
        });
      }
      const slots = byId("wedding-booking-slots");
      if (slots) {
        slots.addEventListener("click", (e) => {
          const btn = e.target.closest(".wedding-booking-slot[data-time]");
          if (!btn || btn.disabled) return;
          chooseTime(btn.dataset.time, true);
        });
      }
      const err = byId("wedding-booking-calErr");
      if (err) {
        err.addEventListener("click", (e) => {
          if (e.target.closest("[data-wedding-booking-retry]")) retryAvailability();
        });
      }
    }
    if (availability.loaded) startMonth();
    renderCalendar();
    renderSlots();
  }

  function renderCalendar() {
    const grid = byId("wedding-booking-calGrid");
    const ml = byId("wedding-booking-calMonth");
    if (!grid || !ml || grid.dataset.weddingBookingSkel === "1") return;
    const yr = calDate.getFullYear();
    const mo = calDate.getMonth();
    const days = new Date(yr, mo + 1, 0).getDate();
    const lead = (new Date(yr, mo, 1).getDay() - weekStartDay() + 7) % 7;
    const today = todayStr();
    ml.textContent = MONTHS[mo] + " " + yr;

    let html = "";
    for (let i = 0; i < lead; i++) html += '<span aria-hidden="true"></span>';
    for (let d = 1; d <= days; d++) {
      const ds = yr + "-" + pad2(mo + 1) + "-" + pad2(d);
      const st = dateStatus(ds);
      const label = formatDate(ds, true);
      let cls = "wedding-booking-cell";
      if (st === "past" || st === "notice" || st === "window" || st === "closed" || st === "unknown") {
        cls += " wedding-booking-past";
      } else if (st) {
        cls += " wedding-booking-bkd";
      }
      if (ds === today) cls += " wedding-booking-today";
      if (!st && ds === chosenDate) cls += " wedding-booking-sel";
      html +=
        '<span class="' +
        cls +
        '" data-date="' +
        ds +
        '" role="button"' +
        (st
          ? ' aria-disabled="true" aria-label="' +
            escHtml(fill(t("dateUnavailable", "{date} (unavailable)"), { date: label })) +
            '"'
          : ' tabindex="0" aria-pressed="' +
            (ds === chosenDate ? "true" : "false") +
            '" aria-label="' +
            escHtml(label) +
            '"') +
        ">" +
        d +
        "</span>";
    }
    grid.innerHTML = html;

    // Don't page before the current month or past the booking window.
    const prev = byId("wedding-booking-calPrev");
    const next = byId("wedding-booking-calNext");
    const t0 = parseYmd(today);
    if (prev && t0) {
      prev.disabled = yr < t0.getFullYear() || (yr === t0.getFullYear() && mo <= t0.getMonth());
    }
    const maxD = availability.rules && availability.rules.maxDate ? parseYmd(availability.rules.maxDate) : null;
    if (next) {
      next.disabled = !!maxD && (yr > maxD.getFullYear() || (yr === maxD.getFullYear() && mo >= maxD.getMonth()));
    }
  }

  // Update the selected cell in place (keeps keyboard focus where it is).
  function markSelectedCell() {
    const grid = byId("wedding-booking-calGrid");
    if (!grid) return;
    grid.querySelectorAll(".wedding-booking-cell[data-date]").forEach((c) => {
      const on =
        c.dataset.date === chosenDate &&
        c.getAttribute("aria-disabled") !== "true";
      c.classList.toggle("wedding-booking-sel", on);
      if (c.hasAttribute("aria-pressed")) {
        c.setAttribute("aria-pressed", on ? "true" : "false");
      }
    });
  }

  function chooseDate(ds, byUser) {
    // The booking is placed; its date can't change any more.
    if (bookingLocked) return;
    if (dateStatus(ds) !== "") return;
    if (byUser) userTouched = true;
    chosenDate = ds;
    // Keep the start time only if it's still free on the new date.
    if (chosenTime && (!slotsOn() || takenTimes(ds).indexOf(chosenTime) !== -1)) {
      chosenTime = "";
    }
    markSelectedCell();
    renderSlots();
    updateSelDateText();
    hideCalErr();
    clearErr("wedding-booking-s1err");
    renderPartialPaymentOption();
    onSelectionChanged();
    // Picked a new date while already on the payment step: rebuild the
    // summary and the pending order, or the customer would pay for the
    // date the order was created with.
    if (onPayStep()) populatePaymentStep();
  }

  /* -------------------------------------------------
     Start times (when the studio offers them)
  ------------------------------------------------- */
  function renderSlots() {
    const wrap = byId("wedding-booking-slots");
    if (!wrap) return;
    if (!slotsOn() || !availability.loaded) {
      wrap.hidden = true;
      wrap.innerHTML = "";
      return;
    }
    wrap.hidden = false;
    if (!chosenDate) {
      wrap.innerHTML =
        '<p class="wedding-booking-slots-hint">' +
        escHtml(t("pickDateForTimes", "Pick a date to see the available start times.")) +
        "</p>";
      return;
    }
    const taken = takenTimes(chosenDate);
    wrap.innerHTML =
      '<div class="wedding-booking-slots-title" id="wedding-booking-slotsTitle">' +
      escHtml(t("chooseTime", "Choose a start time")) +
      "</div>" +
      '<div class="wedding-booking-slot-grid" role="group" aria-labelledby="wedding-booking-slotsTitle">' +
      availability.slots.times
        .map((tm) => {
          const busy = taken.indexOf(tm) !== -1;
          const sel = !busy && tm === chosenTime;
          const label = formatTime(tm);
          return (
            '<button type="button" class="wedding-booking-slot' +
            (sel ? " wedding-booking-sel" : "") +
            '" data-time="' +
            escHtml(tm) +
            '"' +
            (busy
              ? ' disabled aria-disabled="true" aria-label="' +
                escHtml(fill(t("timeUnavailable", "{time} (unavailable)"), { time: label })) +
                '"'
              : ' aria-pressed="' + (sel ? "true" : "false") + '"') +
            ">" +
            escHtml(label) +
            "</button>"
          );
        })
        .join("") +
      "</div>";
  }

  function chooseTime(tm, byUser) {
    if (bookingLocked || !chosenDate) return;
    if (takenTimes(chosenDate).indexOf(tm) !== -1) return;
    if (byUser) userTouched = true;
    chosenTime = tm;
    const wrap = byId("wedding-booking-slots");
    if (wrap) {
      wrap.querySelectorAll(".wedding-booking-slot[data-time]").forEach((b) => {
        const on = b.dataset.time === tm && !b.disabled;
        b.classList.toggle("wedding-booking-sel", on);
        if (!b.disabled) b.setAttribute("aria-pressed", on ? "true" : "false");
      });
    }
    updateSelDateText();
    hideCalErr();
    clearErr("wedding-booking-s1err");
    saveProgress();
    if (onPayStep()) populatePaymentStep();
  }

  function updateSelDateText() {
    if (!chosenDate) {
      setTxt("wedding-booking-selDate", "");
      return;
    }
    setTxt(
      "wedding-booking-selDate",
      chosenTime && slotsOn()
        ? fill(t("selectedDateTime", "Selected: {date} at {time}"), {
            date: formatDate(chosenDate, true),
            time: formatTime(chosenTime),
          })
        : fill(t("selectedDate", "Selected: {date}"), {
            date: formatDate(chosenDate, true),
          }),
    );
  }

  function showCalErr(msg, withRetry) {
    const el = byId("wedding-booking-calErr");
    if (!el) return;
    el.innerHTML =
      escHtml(msg) +
      (withRetry
        ? ' <button type="button" class="wedding-booking-link-btn" data-wedding-booking-retry="1">' +
          escHtml(t("tryAgain", "Try again")) +
          "</button>"
        : "");
    el.hidden = false;
  }

  function hideCalErr() {
    const el = byId("wedding-booking-calErr");
    if (el) {
      el.hidden = true;
      el.textContent = "";
    }
  }

  // Missing date/time: bring the calendar into view (it sits above the form
  // on phones), flash it and move focus there.
  function flagCalendar(msg) {
    const card = byId("wedding-booking-calCard") || document.querySelector(".wedding-booking-side-cal");
    if (!card) return;
    if (msg) showCalErr(msg, false);
    attention(card);
  }

  function attention(el) {
    try {
      el.focus({ preventScroll: true });
    } catch (_e) {
      el.focus();
    }
    el.scrollIntoView({ behavior: scrollBehavior(), block: "center" });
    el.classList.remove("wedding-booking-attn");
    void el.offsetWidth; // restart the animation
    el.classList.add("wedding-booking-attn");
    clearTimeout(el.weddingBookingAttnTimer);
    el.weddingBookingAttnTimer = setTimeout(() => el.classList.remove("wedding-booking-attn"), 2000);
  }

  // The server refused the date/time (taken meanwhile, or a rule): mark it
  // unavailable here too and clear the selection.
  function markDateTaken(status) {
    if (!chosenDate) return;
    const ds = chosenDate;
    localUnavailable[ds] = status || "booked";
    availability.unavailable[ds] = localUnavailable[ds];
    chosenDate = null;
    chosenTime = "";
    afterSelectionCleared();
  }

  function markTimeTaken() {
    if (!chosenDate || !chosenTime) return;
    const ds = chosenDate;
    localTaken[ds] = (localTaken[ds] || []).concat([chosenTime]);
    availability.slots.taken[ds] = takenTimes(ds).concat([chosenTime]);
    chosenTime = "";
    // The whole day may now be full.
    if (dateStatus(ds) !== "") chosenDate = null;
    afterSelectionCleared();
  }

  function afterSelectionCleared() {
    renderCalendar();
    renderSlots();
    updateSelDateText();
    renderPartialPaymentOption();
    onSelectionChanged();
  }

  // What still has to be picked in the calendar card ('' = nothing).
  function selectionProblem() {
    if (!chosenDate) {
      return t("errPickDate", "Please pick your session date from the calendar.");
    }
    if (slotsOn() && !chosenTime) {
      return t("errPickTime", "Please choose a start time under the calendar.");
    }
    return "";
  }

  // The Package step's Continue button. The session date is chosen in the
  // sidebar calendar, so it's validated on click (s1Next) rather than
  // gating the button — a package selection is enough to enable it.
  function updatePkgNextState() {
    const btn = byId("wedding-booking-s1NextBtn");
    if (!btn) return;
    const ready = !!selectedPkg;
    btn.disabled = !ready;
    btn.title = ready ? "" : t("selectPackageTitle", "Select a package to continue");
  }

  /* -------------------------------------------------
     Details step (checkout form fields)
  ------------------------------------------------- */
  function collectDetails() {
    const out = {};
    document.querySelectorAll("[data-wedding-booking-cf]").forEach((el) => {
      out[el.getAttribute("data-wedding-booking-cf")] = String(el.value || "").trim();
    });
    return out;
  }

  function detailValue(key) {
    const el = document.querySelector('[data-wedding-booking-cf="' + key + '"]');
    return el ? String(el.value || "").trim() : "";
  }

  function markInvalid(el, errId) {
    el.setAttribute("aria-invalid", "true");
    el.setAttribute("aria-describedby", errId);
  }

  function clearInvalid(el) {
    el.removeAttribute("aria-invalid");
    if (el.getAttribute("aria-describedby") === "wedding-booking-s2err") {
      el.removeAttribute("aria-describedby");
    }
  }

  function validateDetails() {
    const els = document.querySelectorAll("[data-wedding-booking-cf]");
    els.forEach(clearInvalid);
    const fail = (el, msg) => {
      showErr("wedding-booking-s2err", msg);
      markInvalid(el, "wedding-booking-s2err");
      el.focus();
      return false;
    };
    for (const el of els) {
      const key = el.getAttribute("data-wedding-booking-cf");
      const label = el.getAttribute("data-label") || key;
      const required = el.getAttribute("data-required") === "1";
      const value = String(el.value || "").trim();

      if (required && value === "") {
        return fail(el, fill(t("errRequired", "{label} is required."), { label }));
      }
      if (key === "email" && value !== "" && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
        return fail(el, t("errEmail", "Please enter a valid email."));
      }
      if (key === "phone" && value !== "" && !/^[+]?[0-9 \-()]{7,20}$/.test(value)) {
        return fail(
          el,
          t("errPhone", "Please enter a valid phone number (digits, +, spaces, dashes only)."),
        );
      }
      if (key === "participants" && (required || value !== "")) {
        if (parseInt(value, 10) < 1 || isNaN(parseInt(value, 10))) {
          return fail(el, fill(t("errMin1", "{label} must be at least 1."), { label }));
        }
      }
    }
    clearErr("wedding-booking-s2err");
    return true;
  }

  function initDetailsWatch() {
    const grid = byId("wedding-booking-detailsGrid");
    if (!grid) return;
    let timer = null;
    const onEdit = (e) => {
      const el = e.target.closest("[data-wedding-booking-cf]");
      if (!el) return;
      if (e.isTrusted) userTouched = true;
      if (el.getAttribute("aria-invalid") === "true") clearInvalid(el);
      clearTimeout(timer);
      timer = setTimeout(saveProgress, 300);
    };
    grid.addEventListener("input", onEdit);
    grid.addEventListener("change", onEdit);
  }

  /* -------------------------------------------------
     Step navigation
  ------------------------------------------------- */
  function onPayStep() {
    const el = byId("wedding-booking-s" + PAY_STEP);
    return !!(el && el.classList.contains("wedding-booking-act"));
  }

  function updateStepIndicator(target) {
    for (let i = 1; i <= TOTAL_STEPS; i++) {
      const sp = byId("wedding-booking-sp" + i);
      if (!sp) continue;
      sp.classList.remove("wedding-booking-active", "wedding-booking-done");
      if (i === target) sp.classList.add("wedding-booking-active");
      else if (i < target) sp.classList.add("wedding-booking-done");

      if (i === target) sp.setAttribute("aria-current", "step");
      else sp.removeAttribute("aria-current");

      // Completed steps are reachable by keyboard too.
      if (i < target && !bookingLocked) {
        sp.setAttribute("role", "button");
        sp.setAttribute("tabindex", "0");
        sp.setAttribute(
          "aria-label",
          fill(t("stepGoBack", "Go back to step {n}: {label}"), {
            n: i,
            label: sp.getAttribute("data-label") || sp.textContent.trim(),
          }),
        );
      } else {
        sp.removeAttribute("role");
        sp.removeAttribute("tabindex");
        sp.removeAttribute("aria-label");
      }
    }
  }

  function bkGo(step, opts) {
    opts = opts || {};
    const target = Math.max(1, Math.min(TOTAL_STEPS, parseInt(step, 10) || 1));
    document
      .querySelectorAll(".wedding-booking-wrap .wedding-booking-step")
      .forEach((el) => el.classList.remove("wedding-booking-act"));
    updateStepIndicator(target);
    currentStep = target;
    const el = byId("wedding-booking-s" + target);
    if (el) {
      el.classList.add("wedding-booking-act");
      if (opts.focus !== false) focusStep(el);
    }
    saveProgress();
  }

  // Move focus to the new step's heading; scroll only when the form's top
  // is out of view, so short hops don't jump the page.
  function focusStep(stepEl) {
    const card = stepEl.closest(".wedding-booking-card") || stepEl;
    const top = card.getBoundingClientRect().top;
    if (top < 0 || top > window.innerHeight * 0.6) {
      stepEl.scrollIntoView({ behavior: scrollBehavior(), block: "start" });
    }
    const h = stepEl.querySelector(".wedding-booking-title");
    if (h) {
      if (!h.hasAttribute("tabindex")) h.setAttribute("tabindex", "-1");
      try {
        h.focus({ preventScroll: true });
      } catch (_e) {
        h.focus();
      }
    }
  }

  // Completed steps in the indicator are clickable (and keyboard-operable)
  // to go back.
  function initStepIndicator() {
    for (let i = 1; i <= TOTAL_STEPS; i++) {
      const sp = byId("wedding-booking-sp" + i);
      if (!sp) continue;
      const go = () => {
        if (bookingLocked) return;
        if (sp.classList.contains("wedding-booking-done")) bkGo(i);
      };
      sp.addEventListener("click", go);
      sp.addEventListener("keydown", (e) => {
        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          go();
        }
      });
    }
    updateStepIndicator(1);
  }

  // Step 1 — Package. The date (and start time) comes from the sidebar
  // calendar, so both are required before moving on to the Details step.
  function s1Next() {
    if (!selectedPkg) {
      showErr("wedding-booking-s1err", t("errSelectPackage", "Please select a package."));
      return;
    }
    const missing = selectionProblem();
    if (missing) {
      showErr("wedding-booking-s1err", missing);
      flagCalendar(missing);
      return;
    }
    // Browsing is open to everyone; booking needs an account when the
    // studio requires one.
    if (D.loginRequired) {
      showLoginPrompt();
      return;
    }
    clearErr("wedding-booking-s1err");
    collectAddons();
    renderPartialPaymentOption();
    renderQuote(currentQuote());
    // Warm the gateway list while the customer fills in their details, so
    // the payment step has it ready without delaying the initial page load.
    prefetchGateways();
    bkGo(2);
  }

  // Step 2 — Details → Contract (if enabled) or straight to Payment.
  function s2Next() {
    if (!validateDetails()) {
      return;
    }
    collectAddons();
    saveProgress();
    if (contractEnabled) {
      bkGo(3);
      return;
    }
    populatePaymentStep();
    bkGo(3);
  }

  // Step 3 — Contract. The terms must be accepted (and signed, when the
  // studio asks for a signature) before the payment step is built — in
  // direct mode arriving there already places the order.
  function s3Next() {
    const box = byId("wedding-booking-contractAccept");
    const sig = byId("wedding-booking-contractSignature");
    if (!box || !box.checked) {
      showErr(
        "wedding-booking-s3err",
        D.contractRequiredMsg || "Please accept the Terms & Conditions to continue.",
      );
      if (box) box.focus();
      return;
    }
    if (signatureRequired && contractSignatureLength() < 2) {
      showErr("wedding-booking-s3err", t("signatureRequired", "Please type your full name to sign."));
      if (sig) {
        markInvalid(sig, "wedding-booking-s3err");
        sig.focus();
      }
      return;
    }
    clearErr("wedding-booking-s3err");
    populatePaymentStep();
    bkGo(PAY_STEP);
  }

  function contractSignature() {
    const el = byId("wedding-booking-contractSignature");
    return el ? String(el.value || "").trim() : "";
  }

  function contractSignatureLength() {
    return Array.from(contractSignature()).length;
  }

  function contractAccepted() {
    if (!contractEnabled) return true;
    const box = byId("wedding-booking-contractAccept");
    if (!box || !box.checked) return false;
    return !signatureRequired || contractSignatureLength() >= 2;
  }

  // Keep the Continue button in step with the acceptance checkbox (and the
  // typed signature), so the requirement reads as a state rather than only
  // as an error after a click.
  function initContractStep() {
    if (!contractEnabled) return;
    const box = byId("wedding-booking-contractAccept");
    const btn = byId("wedding-booking-s3NextBtn");
    const sig = byId("wedding-booking-contractSignature");
    if (!box || !btn) return;
    const sync = () => {
      const ok = contractAccepted();
      btn.disabled = !ok;
      btn.title = ok
        ? ""
        : signatureRequired
          ? t("acceptAndSign", "Accept the terms and type your full name to continue")
          : t("acceptTerms", "Accept the terms to continue");
      if (ok) clearErr("wedding-booking-s3err");
      if (sig && contractSignatureLength() >= 2) clearInvalidAny(sig);
    };
    box.addEventListener("change", sync);
    if (sig) sig.addEventListener("input", sync);
    sync();
  }

  function clearInvalidAny(el) {
    el.removeAttribute("aria-invalid");
    if (el.id === "wedding-booking-contractSignature") {
      el.setAttribute("aria-describedby", "wedding-booking-contractSignHint");
    }
  }

  /* -------------------------------------------------
     Log-in prompt (bookings that need an account)
  ------------------------------------------------- */
  // Return here after logging in, with the chosen package in the URL (the
  // rest of the selection comes back from sessionStorage).
  function returnUrl() {
    try {
      const u = new URL(window.location.href);
      u.hash = "";
      if (selectedPkg && !document.querySelector(".wedding-booking-wrap[data-package]")) {
        u.searchParams.set("wedding_booking_package", selectedPkg.slug || String(selectedPkg.id));
      }
      return u.toString();
    } catch (_e) {
      return window.location.href;
    }
  }

  function updateLoginLinks() {
    const login = D.login || {};
    const param = login.param || "redirect";
    document.querySelectorAll("[data-wedding-booking-login]").forEach((a) => {
      const base =
        a.getAttribute("data-wedding-booking-login") === "register"
          ? login.registerUrl
          : login.url;
      if (!base) return;
      a.href =
        base +
        (base.indexOf("?") === -1 ? "?" : "&") +
        param +
        "=" +
        encodeURIComponent(returnUrl());
    });
  }

  function showLoginPrompt(msg) {
    const text =
      msg ||
      t("loginRequired", "Please log in or create an account to continue with your booking.");
    showErr(stepErrId(), text);
    saveProgress();
    const card = byId("wedding-booking-loginCard");
    if (!card) return;
    card.hidden = false;
    updateLoginLinks();
    attention(card);
  }

  /* -------------------------------------------------
     Server error codes → what the customer sees
  ------------------------------------------------- */
  function stepErrId() {
    if (currentStep === 1) return "wedding-booking-s1err";
    if (currentStep === 2) return "wedding-booking-s2err";
    if (contractEnabled && currentStep === 3) return "wedding-booking-s3err";
    return "wedding-booking-payErr";
  }

  function hideEmbed() {
    const box = byId("wedding-booking-embedPay");
    if (box) box.style.display = "none";
  }

  function setCheckoutMsg(text, isErr) {
    const msg = byId("wedding-booking-checkoutMsg");
    if (!msg) return;
    msg.textContent = text || "";
    msg.className = "wedding-booking-checkout-msg" + (isErr ? " wedding-booking-err" : "");
  }

  // Returns true when the error was dealt with (no generic fallback needed).
  function handleServerError(data) {
    const code = data && data.code ? String(data.code) : "";
    const msg = data && data.message ? String(data.message) : "";
    if (!code) return false;

    // Taken meanwhile: mark it, clear it, ask for another date/time.
    if (code === "wedding_booking_date_taken" || code === "wedding_booking_slot_taken" || code === "wedding_booking_slot_invalid" || code === "wedding_booking_slot_required") {
      if (code === "wedding_booking_date_taken") markDateTaken("booked");
      else if (code === "wedding_booking_slot_taken") markTimeTaken();
      else {
        chosenTime = "";
        afterSelectionCleared();
      }
      hideEmbed();
      if (onPayStep()) setCheckoutMsg(msg, true);
      else showErr(stepErrId(), msg);
      flagCalendar(msg);
      return true;
    }

    // A booking-window rule (past, notice, window, closed weekday): back to
    // the calendar.
    if (code.indexOf("wedding_booking_date") === 0) {
      if (code !== "wedding_booking_date") markDateTaken("closed");
      hideEmbed();
      setCheckoutMsg("", false);
      bkGo(1, { focus: false });
      showErr("wedding-booking-s1err", msg);
      flagCalendar(msg);
      return true;
    }

    if (code === "wedding_booking_contract_changed") {
      hideEmbed();
      setCheckoutMsg("", false);
      const el = byId(stepErrId());
      if (el) {
        el.innerHTML =
          escHtml(msg) +
          ' <button type="button" class="wedding-booking-link-btn" data-wedding-booking-reload="1">' +
          escHtml(t("refreshPage", "Refresh page")) +
          "</button>";
        const btn = el.querySelector("[data-wedding-booking-reload]");
        if (btn) {
          btn.addEventListener("click", () => {
            saveProgress();
            window.location.reload();
          });
        }
      }
      return true;
    }

    if ((code === "wedding_booking_contract_required" || code === "wedding_booking_signature_required") && contractEnabled) {
      hideEmbed();
      setCheckoutMsg("", false);
      bkGo(3);
      showErr("wedding-booking-s3err", msg);
      return true;
    }

    if (code === "wedding_booking_login_required") {
      D.loginRequired = true;
      hideEmbed();
      setCheckoutMsg("", false);
      showLoginPrompt(msg);
      return true;
    }

    if (code === "wedding_booking_coupon_invalid") {
      couponCode = "";
      showPromoMsg(msg, true);
      renderQuote(currentQuote());
      schedulePreview(0);
      if (onPayStep() && D.hasWC && checkoutMode === "direct") {
        autoLoadPayment();
      }
      return true;
    }

    if (code === "wedding_booking_package" || code === "wedding_booking_addon") {
      hideEmbed();
      setCheckoutMsg("", false);
      bkGo(1);
      showErr("wedding-booking-s1err", msg);
      return true;
    }

    return false;
  }

  /* -------------------------------------------------
     Promo code
  ------------------------------------------------- */
  function initPromo() {
    if (!D.couponsEnabled) return;
    const toggle = byId("wedding-booking-promoToggle");
    const form = byId("wedding-booking-promoForm");
    const input = byId("wedding-booking-promoInput");
    const apply = byId("wedding-booking-promoApply");
    const remove = byId("wedding-booking-promoRemove");
    if (!toggle || !form || !input || !apply) return;

    toggle.addEventListener("click", () => {
      const open = form.hidden;
      form.hidden = !open;
      toggle.setAttribute("aria-expanded", open ? "true" : "false");
      if (open) input.focus();
    });
    apply.addEventListener("click", applyCoupon);
    input.addEventListener("keydown", (e) => {
      if (e.key === "Enter") {
        e.preventDefault();
        applyCoupon();
      }
    });
    input.addEventListener("input", () => {
      input.removeAttribute("aria-invalid");
      showPromoMsg("", false);
    });
    if (remove) remove.addEventListener("click", removeCoupon);
  }

  function showPromoMsg(text, isErr) {
    const el = byId("wedding-booking-promoMsg");
    if (!el) return;
    el.textContent = text || "";
    el.classList.toggle("wedding-booking-ok", !!text && !isErr);
    const input = byId("wedding-booking-promoInput");
    if (input) {
      if (text && isErr) {
        input.setAttribute("aria-invalid", "true");
        input.setAttribute("aria-describedby", "wedding-booking-promoMsg");
      } else {
        input.removeAttribute("aria-invalid");
      }
    }
  }

  function renderPromoUi() {
    const toggle = byId("wedding-booking-promoToggle");
    const form = byId("wedding-booking-promoForm");
    if (!toggle || !form) return;
    // One code at a time: while one is applied, only "Remove" is offered.
    toggle.hidden = !!couponCode;
    if (couponCode) {
      form.hidden = true;
      toggle.setAttribute("aria-expanded", "false");
    }
  }

  function applyCoupon() {
    if (!D.couponsEnabled || !selectedPkg) return;
    const input = byId("wedding-booking-promoInput");
    const btn = byId("wedding-booking-promoApply");
    if (!input || !btn) return;
    const code = String(input.value || "").trim();
    if (!code) {
      showPromoMsg(t("promoEmpty", "Please enter a promo code."), true);
      input.focus();
      return;
    }
    showPromoMsg("", false);
    const orig = btn.textContent;
    btn.disabled = true;
    btn.textContent = t("promoChecking", "Checking…");
    clearTimeout(previewTimer);

    runPreview(code)
      .then((res) => {
        if (!res || !res.success || !res.data) {
          showPromoMsg(
            (res && res.data && res.data.message) ||
              t("somethingWrong", "Something went wrong. Please try again."),
            true,
          );
          return;
        }
        if (res.data.couponError) {
          // Priced without the code — the total stays as it was.
          showPromoMsg(String(res.data.couponError), true);
          input.focus();
          return;
        }
        couponCode = String(res.data.couponCode || code);
        input.value = "";
        showPromoMsg(t("promoApplied", "Promo code applied."), false);
        renderQuote(currentQuote());
        if (!(serverQuote && serverQuote.key === selectionBase() + "|" + couponCode)) {
          schedulePreview(0);
        }
        // The code is part of the order: replace the auto-placed one.
        if (onPayStep() && D.hasWC && checkoutMode === "direct") {
          autoLoadPayment();
        }
      })
      .catch(() => {
        showPromoMsg(t("networkError", "Network error. Check your connection and try again."), true);
      })
      .then(() => {
        btn.disabled = false;
        btn.textContent = orig;
      });
  }

  function removeCoupon() {
    if (!couponCode) return;
    couponCode = "";
    showPromoMsg(t("promoRemoved", "Promo code removed."), false);
    renderQuote(currentQuote());
    schedulePreview(0);
    const toggle = byId("wedding-booking-promoToggle");
    if (toggle) toggle.focus();
    if (onPayStep() && D.hasWC && checkoutMode === "direct") {
      autoLoadPayment();
    }
  }

  /* -------------------------------------------------
     Payment step
  ------------------------------------------------- */
  function updateSummary() {
    if (!selectedPkg) return;
    collectAddons();
    const activeSess = sessions.find(
      (s) => parseInt(s.id, 10) === activeSessionId,
    );

    // Emoji values arrive HTML-encoded (wp_encode_emoji), so these rows are
    // built as HTML with the text parts escaped — never plain textContent.
    setHtml(
      "wedding-booking-sum-session",
      activeSess
        ? (activeSess.emoji ? iconHtml(activeSess.emoji) + " " : "") +
            escHtml(activeSess.name)
        : "—",
    );
    setHtml(
      "wedding-booking-sum-pkg",
      escHtml(selectedPkg.name) +
        (selectedPkg.duration
          ? ' <span class="wedding-booking-sum-dur">(' +
            escHtml(selectedPkg.duration) +
            ")</span>"
          : "") +
        " — " +
        escHtml(formatMoney(selectedPkg.price)),
    );
    setTxt("wedding-booking-sum-date", formatDate(chosenDate, true));
    const timeRow = byId("wedding-booking-sum-time-row");
    if (timeRow) timeRow.style.display = slotsOn() && chosenTime ? "" : "none";
    setTxt("wedding-booking-sum-time", chosenTime ? formatTime(chosenTime) : "—");
    setHtml(
      "wedding-booking-sum-addons",
      chosenAddons.length
        ? chosenAddons
            .map(
              (a) =>
                (a.emoji ? iconHtml(a.emoji) + " " : "") +
                escHtml(a.name) +
                ' <span class="wedding-booking-sum-addon-price">(+' +
                escHtml(formatMoney(a.price)) +
                ")</span>",
            )
            .join(", ")
        : escHtml(t("none", "None")),
    );
    renderQuote(currentQuote());
    schedulePreview(0);
  }

  function populatePaymentStep() {
    if (!selectedPkg) return;
    updateSummary();
    if (D.hasWC && checkoutMode === "direct") {
      // The WooCommerce payment section loads automatically on arrival —
      // no duplicate method list and no "place booking" button click.
      autoLoadPayment();
      return;
    }
    renderPaymentGateways();
    // Classic layout on (re)entry — the embedded payment section only
    // appears after "Place Booking & Pay" is clicked.
    hideEmbed();
    const btn = byId("wedding-booking-checkoutBtn");
    if (btn) {
      btn.disabled = false;
      btn.style.display = "";
    }
  }

  /* -------------------------------------------------
     Direct mode — create/refresh the pending order as
     soon as the customer arrives on the payment step and
     embed the WooCommerce payment section, so the native
     gateway list (PayPal buttons, card fields, Pay
     button) shows without any extra click.
  ------------------------------------------------- */
  function autoLoadPayment() {
    if (bookingLocked) return;
    const btn = byId("wedding-booking-checkoutBtn");
    if (btn) btn.style.display = "none";
    const gatewayBox = byId("wedding-booking-gatewayBox");
    if (gatewayBox) gatewayBox.style.display = "none";
    clearErr("wedding-booking-payErr");

    // No date/time (e.g. it was just taken): nothing to order yet.
    const missing = selectionProblem();
    if (missing) {
      hideEmbed();
      setCheckoutMsg(missing, true);
      flagCalendar(missing);
      return;
    }

    // One order request at a time; a change made meanwhile re-runs this
    // once the current one settles (it then supersedes that order).
    if (placeInFlight) {
      placeQueued = true;
      return;
    }

    const payload = buildOrderPayload("");
    const snapshot = JSON.stringify(payload);

    // Booking unchanged since its order was created — just show it again
    // instead of superseding the order with an identical one.
    if (
      embedOrder &&
      embedOrder.snapshot === snapshot &&
      byId("wedding-booking-embedPayFrame")
    ) {
      applyEmbedLayout();
      setCheckoutMsg("", false);
      return;
    }

    const msg = byId("wedding-booking-checkoutMsg");
    if (msg) {
      msg.textContent = t("loadingPayment", "Loading payment options…");
      msg.className = "wedding-booking-checkout-msg wedding-booking-is-loading-pay";
    }

    if (embedOrder) {
      // The booking was edited — supersede the previous pending order, and
      // take its payment form away meanwhile so it can't be paid by mistake.
      payload.previous_order_id = embedOrder.id;
      payload.previous_order_key = embedOrder.key;
      hideEmbed();
    }

    placeInFlight = true;
    post("wedding_booking_place_order", payload)
      .then((r) => {
        const d = (r && r.data) || {};
        if (r && r.success && d.order_id) {
          embedOrder = {
            id: d.order_id,
            key: d.order_key || "",
            snapshot: snapshot,
          };
        }
        if (placeQueued) return; // superseded before it was shown
        if (r && r.success && d.embed_url) {
          setCheckoutMsg("", false);
          showEmbeddedPayment(d, true);
        } else if (r && r.success && d.redirect_url) {
          window.location.href = d.redirect_url;
        } else if (r && r.success && d.order_id && d.payment_processed) {
          // Nothing to pay here: a free booking, or a method that settles
          // offline (bank transfer, cheque, cash).
          setCheckoutMsg("", false);
          showBookingConfirmation(d);
        } else if (!(r && r.success) && handleServerError(d)) {
          /* shown by handleServerError */
        } else {
          paymentAutoLoadFailed(
            d.message ||
              t("paymentLoadFailed", "Could not load the payment options. Please try again."),
          );
        }
      })
      .catch(() => {
        if (placeQueued) return;
        paymentAutoLoadFailed(
          t("networkError", "Network error. Check your connection and try again."),
        );
      })
      .then(() => {
        placeInFlight = false;
        if (placeQueued) {
          placeQueued = false;
          if (onPayStep()) autoLoadPayment();
        }
      });
  }

  // Fall back to the manual method list + button so the customer is
  // never stuck on the payment step if the automatic order creation fails.
  function paymentAutoLoadFailed(text) {
    // An order for an earlier version of this booking may still be embedded.
    hideEmbed();
    setCheckoutMsg(text, true);
    renderPaymentGateways();
    const btn = byId("wedding-booking-checkoutBtn");
    if (btn) {
      btn.disabled = false;
      btn.style.display = "";
    }
  }

  function getSelectedGateway() {
    const checked = document.querySelector('input[name="wedding-booking-gateway"]:checked');
    return checked ? checked.value : "";
  }

  function renderPaymentGateways(loadFailed) {
    const wrap = byId("wedding-booking-gatewayList");
    const box = byId("wedding-booking-gatewayBox");
    if (!wrap || !box) return;

    if (!D.hasWC) {
      box.style.display = "none";
      return;
    }

    box.style.display = "";

    const note = (text) =>
      '<p class="wedding-booking-gateway-loading">' + escHtml(text) + "</p>";

    if (loadFailed) {
      wrap.innerHTML = note(
        t(
          "gatewaysLoadFailed",
          "Could not load payment methods. You can still continue — payment options will be shown on the payment page.",
        ),
      );
      return;
    }

    // Gateways load lazily (see prefetchGateways). While the request is in
    // flight, show the loading note and paint the list once it settles.
    if (!gatewaysLoaded) {
      wrap.innerHTML = note(t("gatewaysLoading", "Loading payment methods…"));
      prefetchGateways().then(() => renderPaymentGateways(loadFailed));
      return;
    }

    if (!paymentGateways.length) {
      wrap.innerHTML = note(
        t(
          "noGateways",
          "No payment methods are enabled in WooCommerce yet. Enable one under WooCommerce → Settings → Payments.",
        ),
      );
      return;
    }

    // Selectable in direct mode; informational in redirect mode (the gateway
    // is picked again on the WooCommerce checkout page).
    const selectable = checkoutMode !== "redirect";

    // Same markup WooCommerce prints on its checkout page
    // (ul.wc_payment_methods > li.wc_payment_method + div.payment_box), so
    // gateway icons and descriptions render exactly like the native checkout.
    wrap.innerHTML =
      '<ul class="wedding-booking-wc-methods wc_payment_methods payment_methods methods">' +
      paymentGateways
        .map((gateway, i) => {
          const id = escHtml(gateway.id);
          const icon = gateway.icon || "";
          const desc = gateway.description
            ? "<p>" + gateway.description + "</p>"
            : "";
          // Gateways with their own secure payment fields (cards, PayPal
          // buttons) can only render them on the WooCommerce payment page,
          // so tell the customer where the card form will appear.
          const payNote =
            selectable && gateway.needs_payment_page
              ? '<p class="wedding-booking-pay-next-note">' +
                escHtml(
                  t(
                    "payNextNote",
                    "The secure payment form will open below once you place the booking.",
                  ),
                ) +
                "</p>"
              : "";
          const descBox =
            desc || payNote
              ? '<div class="payment_box payment_method_' +
                id +
                '"' +
                (selectable && i !== 0 ? ' style="display:none"' : "") +
                ">" +
                desc +
                payNote +
                "</div>"
              : "";
          const input = selectable
            ? '<input type="radio" class="input-radio" name="wedding-booking-gateway" id="wedding-booking-gw-' +
              id +
              '" value="' +
              id +
              '"' +
              (i === 0 ? " checked" : "") +
              ">"
            : "";
          return (
            '<li class="wedding-booking-wc-method wc_payment_method payment_method_' +
            id +
            '">' +
            input +
            "<label" +
            (selectable ? ' for="wedding-booking-gw-' + id + '"' : "") +
            ">" +
            gateway.title +
            icon +
            "</label>" +
            descBox +
            "</li>"
          );
        })
        .join("") +
      "</ul>";

    if (selectable) {
      // WooCommerce checkout behavior: only the selected method's
      // description box is open.
      wrap.querySelectorAll('input[name="wedding-booking-gateway"]').forEach((input) => {
        input.addEventListener("change", () => {
          wrap.querySelectorAll(".payment_box").forEach((b) => {
            b.style.display = "none";
          });
          const li = input.closest("li");
          const own = li ? li.querySelector(".payment_box") : null;
          if (own) own.style.display = "";
        });
      });
    }
  }

  function buildOrderPayload(paymentMethod) {
    collectAddons();
    const addonsTotal = chosenAddons.reduce(
      (s, a) => s + parseFloat(a.price || 0),
      0,
    );
    const session = sessions.find(
      (s) => parseInt(s.id, 10) === activeSessionId,
    );
    const payload = {
      session_type: session ? session.name : "",
      package_name: selectedPkg ? selectedPkg.name : "",
      package_id: selectedPkg ? selectedPkg.id : 0,
      addon_ids: addonIdsCsv(),
      addons_label: chosenAddons.length
        ? chosenAddons.map((a) => a.name).join(", ")
        : "",
      addons_total: addonsTotal,
      total_raw:
        parseFloat(selectedPkg ? selectedPkg.price || 0 : 0) + addonsTotal,
      use_deposit: useDepositFlag(),
      session_date: chosenDate || "",
      session_time: slotsOn() ? chosenTime : "",
      hold_token: holdToken(),
      coupon_code: couponCode,
      contract_accepted: contractAccepted() ? 1 : 0,
      contract_version: contractInfo.version || "",
      contract_signature: signatureRequired ? contractSignature() : "",
      payment_method: paymentMethod || "",
    };
    Object.entries(collectDetails()).forEach(([k, v]) => {
      payload["details[" + k + "]"] = v;
    });
    return payload;
  }

  /* -------------------------------------------------
     Place order / proceed to checkout
  ------------------------------------------------- */
  function proceedToCheckout() {
    const btn = byId("wedding-booking-checkoutBtn");
    const msg = byId("wedding-booking-checkoutMsg");
    if (!selectedPkg) {
      setCheckoutMsg(
        t("noPackageSelected", "No package selected. Please go back and choose a package."),
        true,
      );
      return;
    }
    const missing = selectionProblem();
    if (missing) {
      setCheckoutMsg(missing, true);
      flagCalendar(missing);
      return;
    }
    // Safety net for the classic/redirect path — the contract step already
    // gates the way in, but the terms must hold for every route to checkout.
    if (!contractAccepted()) {
      bkGo(3);
      showErr(
        "wedding-booking-s3err",
        signatureRequired && byId("wedding-booking-contractAccept") && byId("wedding-booking-contractAccept").checked
          ? t("signatureRequired", "Please type your full name to sign.")
          : D.contractRequiredMsg || "Please accept the Terms & Conditions to continue.",
      );
      return;
    }
    if (!btn || !msg) return;
    if (!btn.dataset.orig) btn.dataset.orig = btn.textContent;
    btn.disabled = true;
    btn.classList.add("wedding-booking-is-loading");
    btn.textContent = t("pleaseWait", "Please wait…");
    setCheckoutMsg(t("preparing", "Preparing your booking…"), false);
    clearErr("wedding-booking-payErr");

    function restoreBtn() {
      btn.disabled = false;
      btn.classList.remove("wedding-booking-is-loading");
      btn.textContent = btn.dataset.orig;
    }
    function fail(r, fallback) {
      restoreBtn();
      const d = (r && r.data) || {};
      if (handleServerError(d)) {
        setCheckoutMsg("", false);
        return;
      }
      setCheckoutMsg(d.message || fallback, true);
    }
    const netFail = () => {
      restoreBtn();
      setCheckoutMsg(
        t("networkError", "Network error. Check your connection and try again."),
        true,
      );
    };

    collectAddons();
    const addonsTotal = chosenAddons.reduce(
      (s, a) => s + parseFloat(a.price || 0),
      0,
    );
    const total = parseFloat(selectedPkg.price || 0) + addonsTotal;
    const session = sessions.find(
      (s) => parseInt(s.id, 10) === activeSessionId,
    );
    const addonsLabel = chosenAddons.length
      ? chosenAddons.map((a) => a.name).join(", ")
      : "";
    const details = collectDetails();
    const sessionTime = slotsOn() ? chosenTime : details.event_time || "";

    if (D.hasWC && checkoutMode === "direct") {
      const payload = buildOrderPayload(getSelectedGateway());
      if (embedOrder) {
        payload.previous_order_id = embedOrder.id;
        payload.previous_order_key = embedOrder.key;
      }

      post("wedding_booking_place_order", payload)
        .then((r) => {
          const d = (r && r.data) || {};
          if (r && r.success && d.embed_url) {
            // Gateway renders its secure fields on the order-pay page —
            // embed that page right here so the customer never leaves.
            restoreBtn();
            embedOrder = {
              id: d.order_id,
              key: d.order_key || "",
              snapshot: null, // method-specific order — recreate after edits
            };
            showEmbeddedPayment(d);
          } else if (r && r.success && d.redirect_url) {
            // External processor — payment must finish there.
            window.location.href = d.redirect_url;
          } else if (r && r.success && d.order_id) {
            embedOrder = { id: d.order_id, key: d.order_key || "", snapshot: null };
            showBookingConfirmation(d);
          } else {
            fail(r, t("somethingWrong", "Something went wrong. Please try again."));
          }
        })
        .catch(netFail);
    } else if (D.hasWC) {
      // Classic mode: add to cart, then WooCommerce checkout page.
      post("wedding_booking_add_to_cart", {
        session_type: session ? session.name : "",
        package_name: selectedPkg.name,
        package_id: selectedPkg.id,
        addon_ids: addonIdsCsv(),
        addons_label: addonsLabel,
        addons_total: addonsTotal,
        total_raw: total,
        use_deposit: useDepositFlag(),
        session_date: chosenDate || "",
        session_time: sessionTime,
        hold_token: holdToken(),
        coupon_code: couponCode,
        contract_accepted: contractAccepted() ? 1 : 0,
        contract_version: contractInfo.version || "",
        contract_signature: signatureRequired ? contractSignature() : "",
        signer_name: signatureRequired ? contractSignature() : "",
        location_pref: details.hotel_place || "",
        notes: details.notes || "",
        client_name: (
          (details.first_name || "") +
          " " +
          (details.last_name || "")
        ).trim(),
        client_email: details.email || "",
        client_phone: details.phone || "",
        client_country: details.country || "",
        address_1: details.address_1 || "",
        city: details.city || "",
        postcode: details.postcode || "",
        participants: details.participants || "",
        room_number: details.room_number || "",
        stay_period: details.stay_period || "",
      })
        .then((r) => {
          if (r && r.success && r.data && r.data.checkout_url) {
            window.location.href = r.data.checkout_url;
          } else {
            fail(r, t("somethingWrong", "Something went wrong. Please try again."));
          }
        })
        .catch(netFail);
    } else {
      post("wedding_booking_submit", {
        name: (
          (details.first_name || "") +
          " " +
          (details.last_name || "")
        ).trim(),
        email: details.email || "",
        phone: details.phone || "",
        pkg: selectedPkg.name,
        total: formatMoney(total),
        date: chosenDate || "",
        time: sessionTime,
        location: details.hotel_place || "",
        notes: details.notes || "",
        signer: signatureRequired ? contractSignature() : "",
      })
        .then((r) => {
          if (r && r.success) {
            bookingLocked = true;
            clearProgress();
            byId("wedding-booking-payWrap").style.display = "none";
            const suc = byId("wedding-booking-sucWrap");
            if (suc) {
              suc.style.display = "block";
              suc.classList.add("wedding-booking-show");
            }
            setTxt("wedding-booking-sucEmail", details.email || "");
            const wa = byId("wedding-booking-waLink");
            if (wa && D.whatsapp) {
              wa.href = "https://wa.me/" + String(D.whatsapp).replace(/\D/g, "");
            }
            const h = byId("wedding-booking-sucTitle");
            if (h) h.focus({ preventScroll: true });
          } else {
            fail(r, t("genericError", "Error. Please try again."));
          }
        })
        .catch(netFail);
    }
  }

  /* -------------------------------------------------
     Embedded payment — load the WooCommerce order-pay
     page (chrome-less) inside the payment step so card
     fields / PayPal buttons render like on checkout.
  ------------------------------------------------- */
  function applyEmbedLayout() {
    // The embedded WooCommerce payment section replaces the duplicate
    // method list and pay button. Back stays available — editing the
    // booking supersedes the order with a fresh one.
    const gatewayBox = byId("wedding-booking-gatewayBox");
    if (gatewayBox) gatewayBox.style.display = "none";
    const checkoutBtn = byId("wedding-booking-checkoutBtn");
    if (checkoutBtn) checkoutBtn.style.display = "none";
    const box = byId("wedding-booking-embedPay");
    if (box) box.style.display = "";
  }

  function showEmbeddedPayment(d, skipScroll) {
    const payWrap = byId("wedding-booking-payWrap");
    if (!payWrap) {
      window.location.href = d.redirect_url || d.pay_url;
      return;
    }

    applyEmbedLayout();
    setCheckoutMsg("", false);

    let box = byId("wedding-booking-embedPay");
    if (!box) {
      box = document.createElement("div");
      box.id = "wedding-booking-embedPay";
      box.className = "wedding-booking-embed-pay";
      box.innerHTML =
        '<div class="wedding-booking-gateway-title">' +
        '<span class="dashicons dashicons-lock" aria-hidden="true"></span>' +
        escHtml(t("securePayment", "Secure Payment")) +
        "</div>" +
        '<div class="wedding-booking-embed-pay-loading" aria-hidden="true">' +
        '<span class="wedding-booking-embed-spinner"></span>' +
        '<span id="wedding-booking-embedPayLoadingText">' +
        escHtml(t("loadingSecurePay", "Loading secure payment…")) +
        "</span></div>" +
        '<iframe id="wedding-booking-embedPayFrame" title="' +
        escHtml(t("securePaymentFrame", "Secure payment")) +
        '" allow="payment"></iframe>' +
        '<p class="wedding-booking-embed-pay-alt">' +
        escHtml(t("havingTrouble", "Having trouble paying?")) +
        ' <a id="wedding-booking-embedPayLink" href="#">' +
        escHtml(t("openPayPage", "Open the secure payment page")) +
        "</a>.</p>";
      payWrap.appendChild(box);

      const frame = byId("wedding-booking-embedPayFrame");
      frame.addEventListener("load", () => {
        let href = "";
        try {
          href = frame.contentWindow.location.href;
        } catch (e) {
          href = ""; // cross-origin page (external gateway step)
        }

        if (href && href.indexOf("order-received") !== -1) {
          // Payment finished — go straight to the in-form success
          // message. The frame stays hidden behind the spinner, so the
          // themed order-details page never flashes inside the box.
          onEmbeddedPaymentComplete(href);
          return;
        }

        // Payment form (or a same-origin retry page, or an external page
        // that needs interaction) — reveal the frame.
        box.classList.remove("wedding-booking-embed-loading");

        if (href) {
          try {
            syncEmbedHeight(frame);
            // The moment this page navigates away (Pay clicked, gateway
            // redirect), hide the frame again so no interim page shows.
            frame.contentWindow.addEventListener("pagehide", () => {
              setTxt(
                "wedding-booking-embedPayLoadingText",
                t("processingPayment", "Processing your payment…"),
              );
              box.classList.add("wedding-booking-embed-loading");
            });
          } catch (e) {
            // Frame navigated away already — ignore.
          }
        }
      });
    }

    const link = byId("wedding-booking-embedPayLink");
    if (link) link.href = d.redirect_url || d.pay_url || "#";
    setTxt("wedding-booking-embedPayLoadingText", t("loadingSecurePay", "Loading secure payment…"));
    box.classList.add("wedding-booking-embed-loading");
    byId("wedding-booking-embedPayFrame").src = d.embed_url;
    box.style.display = "";

    // The Back button belongs below the payment form.
    const nav = payWrap.querySelector(".wedding-booking-nav");
    if (nav) payWrap.appendChild(nav);

    if (!skipScroll) {
      box.scrollIntoView({ behavior: scrollBehavior(), block: "start" });
    }
  }

  /* -------------------------------------------------
     Payment finished inside the embedded frame — show
     the in-form confirmation panel (success message)
     with the order's fresh status instead of leaving
     the booking form for the order-received page.
  ------------------------------------------------- */
  function onEmbeddedPaymentComplete(receivedUrl) {
    if (!embedOrder) {
      window.location.href = receivedUrl;
      return;
    }
    post("wedding_booking_order_confirmation", {
      order_id: embedOrder.id,
      order_key: embedOrder.key,
    })
      .then((r) => {
        if (r && r.success && r.data && r.data.order_id) {
          showBookingConfirmation(r.data);
        } else {
          window.location.href = receivedUrl;
        }
      })
      .catch(() => {
        window.location.href = receivedUrl;
      });
  }

  /* -------------------------------------------------
     Keep the payment iframe as tall as its content —
     no dead white space, and it grows when the card
     form expands. Same-origin, so we can measure it.
  ------------------------------------------------- */
  function syncEmbedHeight(frame) {
    try {
      const doc = frame.contentWindow.document;
      if (!doc || !doc.body) return;

      const apply = () => {
        try {
          const h = Math.ceil(doc.body.getBoundingClientRect().height) + 4;
          frame.style.height = Math.max(h, 260) + "px";
        } catch (e) {
          /* frame navigated away — stop adjusting */
        }
      };
      apply();

      if (frame.weddingBookingResizeObserver) frame.weddingBookingResizeObserver.disconnect();
      if (frame.weddingBookingResizeTimer) clearInterval(frame.weddingBookingResizeTimer);
      if (typeof ResizeObserver !== "undefined") {
        frame.weddingBookingResizeObserver = new ResizeObserver(apply);
        frame.weddingBookingResizeObserver.observe(doc.body);
      } else {
        frame.weddingBookingResizeTimer = setInterval(apply, 800);
      }
    } catch (e) {
      /* cross-origin page in the frame — leave the CSS height */
    }
  }

  /* -------------------------------------------------
     In-place booking confirmation (no page change)
  ------------------------------------------------- */
  function showBookingConfirmation(d) {
    const wrap = byId("wedding-booking-confirmWrap");
    if (!wrap) {
      // Older template without the confirmation panel — fall back to the pay page.
      if (d.pay_url) window.location.href = d.pay_url;
      return;
    }

    bookingLocked = true; // freeze the stepper — the order exists now
    updateStepIndicator(PAY_STEP);
    clearProgress();

    const payWrap = byId("wedding-booking-payWrap");
    if (payWrap) payWrap.style.display = "none";

    // Placed but unpaid (bank transfer, cheque, cash on delivery): the
    // "received" wording plus the gateway's own instructions.
    const awaiting = !!d.awaiting_payment;
    const processed = !!d.payment_processed && !awaiting;

    // Titles and messages are editable in Wedding Booking → Settings → Checkout → Messages after booking.
    setTxt(
      "wedding-booking-confirmTitle",
      processed
        ? D.confirmTitle || "Booking Confirmed!"
        : D.confirmPendingTitle || "Booking Received!",
    );
    const noteTemplate = processed
      ? D.confirmMsg ||
        "Thank you for your booking! A confirmation email has been sent to {email}."
      : D.confirmPendingMsg ||
        "Thank you for your booking! Complete the payment below to confirm your slot.";
    setTxt(
      "wedding-booking-confirmNote",
      fill(noteTemplate, {
        email: d.client_email || t("yourEmail", "your email address"),
      }),
    );
    const instr = byId("wedding-booking-confirmInstructions");
    if (instr) {
      if (awaiting && d.instructions_html) {
        // Gateway instructions (bank details), sanitized server-side.
        instr.innerHTML = d.instructions_html;
        instr.hidden = false;
      } else {
        instr.innerHTML = "";
        instr.hidden = true;
      }
    }
    setTxt("wedding-booking-confirmOrder", "#" + (d.order_number || d.order_id));
    setTxt("wedding-booking-confirmMethod", d.gateway_title || "—");
    setTxt("wedding-booking-confirmAmount", formatMoney(d.due_now || 0));
    setTxt("wedding-booking-confirmStatus", d.status_label || d.status || "—");

    const payBtn = byId("wedding-booking-confirmPayBtn");
    if (payBtn) {
      if (!processed && !awaiting && d.pay_url) {
        payBtn.href = d.pay_url;
        payBtn.style.display = "";
      } else {
        payBtn.style.display = "none";
      }
    }
    const viewBtn = byId("wedding-booking-confirmViewBtn");
    if (viewBtn) {
      if ((processed || awaiting) && d.received_url) {
        viewBtn.href = d.received_url;
        viewBtn.style.display = "";
      } else {
        viewBtn.style.display = "none";
      }
    }
    const waBtn = byId("wedding-booking-confirmWaBtn");
    if (waBtn) {
      if (D.whatsapp) {
        waBtn.href = "https://wa.me/" + String(D.whatsapp).replace(/\D/g, "");
        waBtn.style.display = "";
      } else {
        waBtn.style.display = "none";
      }
    }

    // .suc is display:none by CSS class; the inline block + .show class
    // (fade-in) are both needed to actually reveal the panel.
    wrap.style.display = "block";
    wrap.classList.add("wedding-booking-show");
    wrap.scrollIntoView({ behavior: scrollBehavior(), block: "start" });
    const h = byId("wedding-booking-confirmTitle");
    if (h) {
      try {
        h.focus({ preventScroll: true });
      } catch (_e) {
        h.focus();
      }
    }
  }

  /* -------------------------------------------------
     Keep progress across reloads and the login round trip
     (sessionStorage: this tab only). Saved: package,
     add-ons, date, time, deposit choice, detail fields
     and the step (up to Details). Never the promo code.
  ------------------------------------------------- */
  const PROGRESS_KEY = "wedding_booking_progress:" + window.location.pathname;

  function saveProgress() {
    if (!progressReady || restoring || bookingLocked) return;
    const toggle = byId("wedding-booking-partialToggle");
    const data = {
      v: 1,
      pkg: selectedPkg ? parseInt(selectedPkg.id, 10) : 0,
      addons: Array.from(
        document.querySelectorAll("#wedding-booking-addonsGrid .wedding-booking-ac:checked"),
      ).map((cb) => parseInt(cb.value, 10)),
      date: chosenDate || "",
      time: chosenTime || "",
      deposit:
        toggle && toggle.dataset.touched === "1" ? !!toggle.checked : null,
      details: collectDetails(),
      step: Math.min(currentStep, 2),
      ts: Date.now(),
    };
    try {
      window.sessionStorage.setItem(PROGRESS_KEY, JSON.stringify(data));
    } catch (_e) {
      /* storage full / disabled */
    }
  }

  function clearProgress() {
    try {
      window.sessionStorage.removeItem(PROGRESS_KEY);
    } catch (_e) {
      /* ignore */
    }
  }

  // After the catalog and availability load: put back what's still valid
  // (an inactive package or a now-unavailable date is skipped).
  function restoreProgress() {
    if (progressReady) return;
    let data = null;
    try {
      data = JSON.parse(window.sessionStorage.getItem(PROGRESS_KEY) || "null");
    } catch (_e) {
      data = null;
    }
    if (!data || data.v !== 1 || userTouched || bookingLocked) {
      progressReady = true;
      return;
    }

    restoring = true;
    try {
      const pkg = packages.find(
        (p) => parseInt(p.id, 10) === parseInt(data.pkg, 10),
      );
      const sessionOk =
        pkg &&
        sessions.some((s) => parseInt(s.id, 10) === parseInt(pkg.session_id, 10));
      if (pkg && sessionOk) {
        if (activeSessionId !== parseInt(pkg.session_id, 10)) {
          const tab = document.querySelector(
            '#wedding-booking-typeTabs .wedding-booking-stype-btn[data-id="' + parseInt(pkg.session_id, 10) + '"]',
          );
          if (tab) activateSession(tab);
        }
        const card = document.querySelector(
          '#wedding-booking-pkgGrid .wedding-booking-pkg[data-id="' + parseInt(pkg.id, 10) + '"]',
        );
        if (card) selectCard(card);
        (Array.isArray(data.addons) ? data.addons : []).forEach((id) => {
          const cb = document.querySelector(
            '#wedding-booking-addonsGrid .wedding-booking-ac[value="' + parseInt(id, 10) + '"]',
          );
          if (cb) cb.checked = true;
        });
      }

      if (data.date && /^\d{4}-\d{2}-\d{2}$/.test(data.date) && dateStatus(data.date) === "") {
        chosenDate = data.date;
        if (data.time && slotsOn() && freeTimes(data.date).indexOf(data.time) !== -1) {
          chosenTime = data.time;
        }
        startMonth();
        renderCalendar();
        renderSlots();
        updateSelDateText();
      }

      renderPartialPaymentOption();
      const toggle = byId("wedding-booking-partialToggle");
      if (toggle && !toggle.disabled && typeof data.deposit === "boolean") {
        toggle.checked = data.deposit;
        toggle.dataset.touched = "1";
        usePartialPayment = data.deposit;
        setTxt("wedding-booking-partialNote", partialNoteText(data.deposit));
      }

      if (data.details && typeof data.details === "object") {
        Object.entries(data.details).forEach(([k, v]) => {
          if (!/^[a-z0-9_]+$/i.test(k) || typeof v !== "string") return;
          const el = document.querySelector('[data-wedding-booking-cf="' + k + '"]');
          if (el && !el.value) el.value = v;
        });
      }
    } finally {
      restoring = false;
      progressReady = true;
    }

    onSelectionChanged();
    if (
      parseInt(data.step, 10) >= 2 &&
      selectedPkg &&
      !selectionProblem() &&
      !D.loginRequired
    ) {
      collectAddons();
      prefetchGateways();
      bkGo(2, { focus: false });
    }
    saveProgress();
  }

  /* -------------------------------------------------
     DOM helpers
  ------------------------------------------------- */
  function byId(id) {
    return document.getElementById(id);
  }
  function setTxt(id, text) {
    const el = byId(id);
    if (el) el.textContent = text;
  }
  function setHtml(id, html) {
    const el = byId(id);
    if (el) el.innerHTML = html;
  }
  function setNote(id, text) {
    const el = byId(id);
    if (!el) return;
    el.textContent = text || "";
    el.hidden = !text;
  }
  function showErr(id, text) {
    const el = byId(id);
    if (el) {
      el.textContent = text;
      el.style.display = "";
    }
  }
  function clearErr(id) {
    const el = byId(id);
    if (el) el.textContent = "";
  }
  function escHtml(s) {
    return String(s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  // Icon value may be an emoji or an icon-font class such as
  // "fa-solid fa-camera" — mirrors wedding_booking_icon_html() on the server.
  function iconHtml(v) {
    v = String(v || "").trim();
    if (!v) return "";
    if (/^[a-z0-9 _-]+$/i.test(v) && /(^|\s)(fa-|dashicons)/.test(v)) {
      return '<i class="' + v + '" aria-hidden="true"></i>';
    }
    return v; // emoji arrives HTML-encoded (wp_encode_emoji)
  }

  /* -------------------------------------------------
     Public API
  ------------------------------------------------- */
  window.weddingBooking = {
    bkGo,
    s1Next,
    s2Next,
    s3Next,
    proceedToCheckout,
    formatMoney,
  };

  /* -------------------------------------------------
     Boot
  ------------------------------------------------- */
  document.addEventListener("DOMContentLoaded", () => {
    if (!byId("wedding-booking-s1")) return;
    ["wedding-booking-s1err", "wedding-booking-s2err", "wedding-booking-s3err", "wedding-booking-payErr"].forEach((id) => {
      const el = byId(id);
      if (el) el.setAttribute("role", "alert");
    });

    holdToken();
    init();
    initPartialPaymentOption();
    initStepIndicator();
    initContractStep();
    initPromo();
    initDetailsWatch();
    updateLoginLinks();

    // Restrict phone field to valid phone characters only
    const phoneInput = document.querySelector('[data-wedding-booking-cf="phone"]');
    if (phoneInput) {
      phoneInput.addEventListener("input", () => {
        phoneInput.value = phoneInput.value.replace(/[^0-9+\-() ]/g, "");
      });
    }
  });
})();
