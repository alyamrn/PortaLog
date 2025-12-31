/* globals Chart, window, document */
(function () {
    // ---------- Config ----------
    const API_URL = new URL('api/analytics.php', window.location.href).toString();
    // adjust if your api folder is elsewhere (e.g. './api/analytics.php')

    function getFilters() {
        const p = window.ANALYTICS_FILTERS || {};
        return {
            vessel_id: p.vessel_id || (document.getElementById('vessel_id')?.value || ''),
            start_date: p.start_date || (document.getElementById('start_date')?.value || ''),
            end_date: p.end_date || (document.getElementById('end_date')?.value || '')
        };
    }

    // ---------- Helpers ----------
    const charts = {};
    function mount(id, cfg) {
        const el = document.getElementById(id);
        if (!el) return;
        // If chart existed (e.g., re-initialized after resize), destroy it
        if (charts[id]) charts[id].destroy();
        charts[id] = new Chart(el.getContext('2d'), cfg);
    }
    function markNoData(id, msg) {
        const el = document.getElementById(id);
        if (!el) return;
        const parent = el.parentElement;
        if (!parent) return;
        const stub = document.createElement('div');
        stub.style.padding = '16px';
        stub.style.opacity = '0.8';
        stub.textContent = msg || 'No data';
        // Prevent duplicates
        if (!parent.querySelector('.no-data-stub')) {
            stub.className = 'no-data-stub';
            parent.appendChild(stub);
        }
    }
    function api(action) {
        const q = new URLSearchParams({ action, ...getFilters() });
        return fetch(`${API_URL}?${q.toString()}`, { credentials: 'same-origin' })
            .then(async r => {
                let body;
                try { body = await r.json(); } catch { body = { error: `Non-JSON response (${r.status})` }; }
                if (!r.ok) throw new Error(body?.error || `HTTP ${r.status}`);
                if (body && body.error) throw new Error(body.error);
                return body;
            });
    }

    // ---------- Tab logic (lazy render) ----------
    const btns = Array.from(document.querySelectorAll('.tab-btn'));
    const panels = Array.from(document.querySelectorAll('.tab-panel'));
    const loaded = new Set(); // which tabs we’ve rendered

    function showTab(id) {
        btns.forEach(b => b.classList.toggle('active', b.getAttribute('data-tab') === id));
        panels.forEach(p => p.classList.toggle('active', p.id === id));

        // If not rendered yet, render this tab’s charts
        if (!loaded.has(id)) {
            renderTab(id);
            loaded.add(id);
        }

        // After showing, resize any existing charts to fit the now-visible area
        setTimeout(() => {
            Object.values(charts).forEach(ch => { try { ch.resize(); } catch (_) { } });
        }, 0);
    }

    btns.forEach(b => {
        // ensure these are not submit buttons
        if (!b.getAttribute('type')) b.setAttribute('type', 'button');
        b.addEventListener('click', e => {
            e.preventDefault();
            const id = b.getAttribute('data-tab');
            if (!id) return;
            showTab(id);
        });
    });

    // ---------- Renderers per tab ----------
    function renderTab(id) {
        switch (id) {
            case 'tab-pob': return renderPOB();
            case 'tab-engine': return renderEngine();
            case 'tab-fuel': return renderFuel();
            case 'tab-garbage': return renderGarbage();
            case 'tab-nav': return renderNav();
            case 'tab-oil': return renderOil();
            case 'tab-users': return renderUsers();
            // tab-overview is rendered by PHP already; nothing to do
        }
    }

    // ============ TAB 2: POB ============
    function renderPOB() {
        api('pob_category_share').then(rows => {
            if (!Array.isArray(rows) || rows.length === 0) return markNoData('pobCategoryPie');
            mount('pobCategoryPie', {
                type: 'pie',
                data: { labels: rows.map(r => r.category || 'UNKNOWN'), datasets: [{ data: rows.map(r => +r.cnt || 0) }] },
                options: { plugins: { legend: { position: 'bottom' } } }
            });
        }).catch(err => markNoData('pobCategoryPie', err.message));

        api('pob_daily_total').then(rows => {
            if (!Array.isArray(rows) || rows.length === 0) return markNoData('pobDailyLine');
            mount('pobDailyLine', {
                type: 'line',
                data: { labels: rows.map(r => r.log_date), datasets: [{ label: 'Distinct POB', data: rows.map(r => +r.total || 0), fill: false }] },
                options: { responsive: true }
            });
        }).catch(err => markNoData('pobDailyLine', err.message));

        api('pob_turnover_weekly').then(rows => {
            if (!Array.isArray(rows) || rows.length === 0) return markNoData('pobTurnoverCol');
            mount('pobTurnoverCol', {
                type: 'bar',
                data: {
                    labels: rows.map(r => r.yearweek),
                    datasets: [
                        { label: 'Embark', data: rows.map(r => +r.embark || 0) },
                        { label: 'Disembark', data: rows.map(r => +r.disembark || 0) }
                    ]
                },
                options: { plugins: { legend: { position: 'bottom' } } }
            });
        }).catch(err => markNoData('pobTurnoverCol', err.message));

        api('pob_top_nationalities').then(rows => {
            if (!Array.isArray(rows) || rows.length === 0) return markNoData('pobNationalityBar');
            mount('pobNationalityBar', {
                type: 'bar',
                data: { labels: rows.map(r => r.nationality || 'UNKNOWN'), datasets: [{ label: 'Count', data: rows.map(r => +r.cnt || 0) }] },
                options: { indexAxis: 'y', plugins: { legend: { display: false } } }
            });
        }).catch(err => markNoData('pobNationalityBar', err.message));
    }

    // ============ TAB 3: ENGINE ============
    function renderEngine() {
        api('engine_utilization').then(rows => {
            if (!Array.isArray(rows) || rows.length === 0) return markNoData('engUtilMulti');
            const names = [...new Set(rows.map(r => r.machine_name))];
            const days = [...new Set(rows.map(r => r.log_date))].sort();
            const series = names.map(n => ({
                label: n,
                data: days.map(d => {
                    const f = rows.find(r => r.machine_name === n && r.log_date === d);
                    return f ? +f.hours : 0;
                }),
                fill: false
            }));
            mount('engUtilMulti', { type: 'line', data: { labels: days, datasets: series }, options: { responsive: true } });
        }).catch(err => markNoData('engUtilMulti', err.message));

        api('engine_total_today').then(r => {
            const el = document.getElementById('engTotalToday');
            if (el) el.textContent = (r?.total_hours ?? 0) + ' hrs';
        }).catch(() => {
            const el = document.getElementById('engTotalToday');
            if (el) el.textContent = '—';
        });

        api('engine_idle_active').then(r => {
            if (!r || typeof r !== 'object') return markNoData('engIdleActiveGauge');
            const labels = ['Active', 'Idle'];
            const data = [+(r.active_cnt || 0), +(r.idle_cnt || 0)];
            if (data[0] === 0 && data[1] === 0) return markNoData('engIdleActiveGauge');
            mount('engIdleActiveGauge', {
                type: 'doughnut',
                data: { labels, datasets: [{ data }] },
                options: { cutout: '70%' }
            });
        }).catch(err => markNoData('engIdleActiveGauge', err.message));
    }

    // ============ TAB 4: FUEL ============
    function renderFuel() {
        api('fuel_usage_daily').then(rows => {
            if (!Array.isArray(rows) || rows.length === 0) return markNoData('fuelDailyBar');
            mount('fuelDailyBar', {
                type: 'bar',
                data: { labels: rows.map(r => r.d), datasets: [{ label: 'Fuel Used', data: rows.map(r => +r.used || 0) }] },
                options: { responsive: true }
            });
        }).catch(err => markNoData('fuelDailyBar', err.message));

        api('water_usage_daily').then(rows => {
            if (!Array.isArray(rows) || rows.length === 0) return markNoData('waterDailyLine');
            mount('waterDailyLine', {
                type: 'line',
                data: { labels: rows.map(r => r.d), datasets: [{ label: 'Fresh Water Used', data: rows.map(r => +r.used || 0), fill: false }] },
                options: { responsive: true }
            });
        }).catch(err => markNoData('waterDailyLine', err.message));

        api('top_liquids_consumed').then(rows => {
            if (!Array.isArray(rows) || rows.length === 0) return markNoData('topLiquidsBar');
            mount('topLiquidsBar', {
                type: 'bar',
                data: { labels: rows.map(r => r.product_name || 'UNKNOWN'), datasets: [{ label: 'Consumed', data: rows.map(r => +r.consumed || 0) }] },
                options: { plugins: { legend: { display: false } } }
            });
        }).catch(err => markNoData('topLiquidsBar', err.message));

        api('stock_capacity_util').then(rows => {
            if (!Array.isArray(rows) || rows.length === 0) return markNoData('stockUtilDonut');
            mount('stockUtilDonut', {
                type: 'doughnut',
                data: { labels: rows.map(r => r.product_name || 'UNKNOWN'), datasets: [{ data: rows.map(r => +r.util_pct || 0) }] },
                options: { plugins: { tooltip: { callbacks: { label: (ctx) => `${ctx.label}: ${ctx.parsed}%` } } } }
            });
        }).catch(err => markNoData('stockUtilDonut', err.message));
    }

    // ============ TAB 5: GARBAGE ============
    function renderGarbage() {
        api('garbage_by_method').then(rows => {
            if (!Array.isArray(rows) || rows.length === 0) return markNoData('garbageMethodPie');
            mount('garbageMethodPie', { type: 'pie', data: { labels: rows.map(r => r.method), datasets: [{ data: rows.map(r => +r.qty || 0) }] } });
        }).catch(err => markNoData('garbageMethodPie', err.message));

        api('garbage_by_category').then(rows => {
            if (!Array.isArray(rows) || rows.length === 0) return markNoData('garbageTypeBar');
            mount('garbageTypeBar', { type: 'bar', data: { labels: rows.map(r => r.category), datasets: [{ label: 'm³', data: rows.map(r => +r.qty || 0) }] } });
        }).catch(err => markNoData('garbageTypeBar', err.message));

        api('garbage_weekly').then(rows => {
            if (!Array.isArray(rows) || rows.length === 0) return markNoData('garbageWeeklyLine');
            mount('garbageWeeklyLine', { type: 'line', data: { labels: rows.map(r => r.yearweek), datasets: [{ label: 'm³', data: rows.map(r => +r.qty || 0), fill: false }] } });
        }).catch(err => markNoData('garbageWeeklyLine', err.message));
    }

    // ============ TAB 6: NAV ============
    function renderNav() {
        api('nav_entries_daily').then(rows => {
            if (!Array.isArray(rows) || rows.length === 0) return markNoData('navDailyLine');
            mount('navDailyLine', { type: 'line', data: { labels: rows.map(r => r.log_date), datasets: [{ label: 'Entries', data: rows.map(r => +r.cnt || 0), fill: false }] } });
        }).catch(err => markNoData('navDailyLine', err.message));

        api('nav_weather_freq').then(rows => {
            if (!Array.isArray(rows) || rows.length === 0) return markNoData('navWeatherPie');
            mount('navWeatherPie', { type: 'pie', data: { labels: rows.map(r => r.weather), datasets: [{ data: rows.map(r => +r.cnt || 0) }] } });
        }).catch(err => markNoData('navWeatherPie', err.message));

        api('nav_avg_speed_daily').then(rows => {
            if (!Array.isArray(rows) || rows.length === 0) return markNoData('navAvgSpeedLine');
            mount('navAvgSpeedLine', { type: 'line', data: { labels: rows.map(r => r.log_date), datasets: [{ label: 'kn', data: rows.map(r => +r.avg_speed || 0), fill: false }] } });
        }).catch(err => markNoData('navAvgSpeedLine', err.message));

        api('nav_top_dest').then(rows => {
            if (!Array.isArray(rows) || rows.length === 0) return markNoData('navTopDestBar');
            mount('navTopDestBar', { type: 'bar', data: { labels: rows.map(r => r.destination || 'UNKNOWN'), datasets: [{ label: 'Count', data: rows.map(r => +r.cnt || 0) }] }, options: { indexAxis: 'y', plugins: { legend: { display: false } } } });
        }).catch(err => markNoData('navTopDestBar', err.message));
    }

    // ============ TAB 7: OIL ============
    function renderOil() {
        api('oil_ops_count').then(rows => {
            if (!Array.isArray(rows) || rows.length === 0) return markNoData('oilOpsPie');
            mount('oilOpsPie', { type: 'pie', data: { labels: rows.map(r => r.operation), datasets: [{ data: rows.map(r => +r.cnt || 0) }] } });
        }).catch(err => markNoData('oilOpsPie', err.message));

        api('oil_qty_by_op').then(rows => {
            if (!Array.isArray(rows) || rows.length === 0) return markNoData('oilQtyCol');
            mount('oilQtyCol', { type: 'bar', data: { labels: rows.map(r => r.operation), datasets: [{ label: 'MT', data: rows.map(r => +r.qty || 0) }] } });
        }).catch(err => markNoData('oilQtyCol', err.message));

        api('maint_trend_weekly').then(rows => {
            if (!Array.isArray(rows) || rows.length === 0) return markNoData('maintTrendLine');
            mount('maintTrendLine', { type: 'line', data: { labels: rows.map(r => r.yearweek), datasets: [{ label: 'Maintenance', data: rows.map(r => +r.cnt || 0), fill: false }] } });
        }).catch(err => markNoData('maintTrendLine', err.message));
    }

    // ============ TAB 8: USERS ============
    function renderUsers() {
        api('users_by_role').then(rows => {
            if (!Array.isArray(rows) || rows.length === 0) return markNoData('usersRoleBar');
            mount('usersRoleBar', { type: 'bar', data: { labels: rows.map(r => r.role || 'UNKNOWN'), datasets: [{ label: 'Users', data: rows.map(r => +r.cnt || 0) }] } });
        }).catch(err => markNoData('usersRoleBar', err.message));

        api('reminders_per_month').then(rows => {
            if (!Array.isArray(rows) || rows.length === 0) return markNoData('remindersMonthLine');
            mount('remindersMonthLine', { type: 'line', data: { labels: rows.map(r => r.ym), datasets: [{ label: 'Reminders', data: rows.map(r => +r.cnt || 0), fill: false }] } });
        }).catch(err => markNoData('remindersMonthLine', err.message));

        api('vessels_activity').then(rows => {
            if (!Array.isArray(rows) || rows.length === 0) return markNoData('activeVesselsBar');
            mount('activeVesselsBar', { type: 'bar', data: { labels: rows.map(r => r.vessel_id), datasets: [{ label: 'Logs', data: rows.map(r => +r.cnt || 0) }] }, options: { plugins: { legend: { display: false } } } });
        }).catch(err => markNoData('activeVesselsBar', err.message));

        api('top_logged_users').then(rows => {
            if (!Array.isArray(rows) || rows.length === 0) return markNoData('topUsersBar');
            mount('topUsersBar', { type: 'bar', data: { labels: rows.map(r => r.full_name), datasets: [{ label: 'CREATE actions', data: rows.map(r => +r.actions || 0) }] }, options: { indexAxis: 'y', plugins: { legend: { display: false } } } });
        }).catch(err => markNoData('topUsersBar', err.message));
    }

    // ---------- Boot ----------
    // render the initially visible tab (should be tab-overview; we skip JS rendering there)
    // but we want the *next* visible tab user clicks to render lazily
    const initiallyActive = document.querySelector('.tab-panel.active')?.id;
    if (initiallyActive && initiallyActive !== 'tab-overview') {
        loaded.add(initiallyActive);
        renderTab(initiallyActive);
    }

    // Also, when the filter form reloads the page, PHP overview updates
    const form = document.getElementById('filtersForm');
    if (form) {
        form.addEventListener('submit', () => {
            // normal submit; on reload, this script will run again with new filters
        });
    }
})();
