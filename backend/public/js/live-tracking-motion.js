(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }
    if (root) {
        root.LiveTrackingMotion = api;
    }
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    const SNAP_UNDER_METERS = 8;
    const TELEPORT_METERS = 30000;

    function toRadians(value) {
        return (value * Math.PI) / 180;
    }

    function distanceMeters(from, to) {
        const earth = 6371000;
        const lat1 = toRadians(from.lat);
        const lat2 = toRadians(to.lat);
        const dLat = toRadians(to.lat - from.lat);
        const dLng = toRadians(to.lng - from.lng);
        const h = Math.sin(dLat / 2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng / 2) ** 2;

        return 2 * earth * Math.asin(Math.min(1, Math.sqrt(h)));
    }

    function bearing(from, to) {
        const lat1 = toRadians(from.lat);
        const lat2 = toRadians(to.lat);
        const dLng = toRadians(to.lng - from.lng);
        const y = Math.sin(dLng) * Math.cos(lat2);
        const x = Math.cos(lat1) * Math.sin(lat2) - Math.sin(lat1) * Math.cos(lat2) * Math.cos(dLng);

        return (Math.atan2(y, x) * 180 / Math.PI + 360) % 360;
    }

    function ease(progress) {
        const t = Math.min(1, Math.max(0, progress));

        return t < 0.5 ? 2 * t * t : 1 - ((-2 * t + 2) ** 2) / 2;
    }

    function lerp(from, to, progress) {
        return from + (to - from) * progress;
    }

    function sameCoord(a, b) {
        return !!a && !!b && Math.abs(a.lat - b.lat) < 1e-6 && Math.abs(a.lng - b.lng) < 1e-6;
    }

    function asPoint(raw) {
        const lat = Number(raw?.latitude ?? raw?.lat);
        const lng = Number(raw?.longitude ?? raw?.lng);
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
            return null;
        }

        return { lat, lng, id: raw?.id == null ? null : Number(raw.id) };
    }

    function initials(name) {
        const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
        if (parts.length === 0) {
            return 'E';
        }
        const first = parts[0].charAt(0);
        const second = parts.length > 1 ? parts[parts.length - 1].charAt(0) : parts[0].charAt(1);

        return (first + second).toUpperCase();
    }

    function markerTone(status) {
        if (status === 'moving') return 'moving';
        if (status === 'stopped') return 'stopped';

        return 'offline';
    }

    function popupStatus(status) {
        if (status === 'moving') return 'Moving';
        if (status === 'stopped') return 'Stopped';

        return 'Offline';
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, (char) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;',
        }[char]));
    }

    function markerHtml(employee, selected) {
        const tone = markerTone(employee.status);
        const name = employee.employee_name || 'Employee';
        const letters = employee.initials || initials(name);
        const showHeading = tone === 'moving' && employee.bearing != null;
        const photo = employee.profile_photo_url
            ? `<img class="emp-pin-photo" src="${escapeHtml(employee.profile_photo_url)}" alt="" onerror="this.style.display='none';var fallback=this.nextElementSibling;if(fallback)fallback.style.display='flex';">`
            : '';
        const initialsStyle = employee.profile_photo_url ? ' style="display:none"' : '';

        return `<div class="emp-pin${selected ? ' is-current' : ''}" data-status="${tone}">
            <div class="emp-pin-head">
                <span class="emp-pin-heading" style="display:${showHeading ? 'block' : 'none'};transform:rotate(${Number(employee.bearing) || 0}deg)"></span>
                <div class="emp-pin-avatar">${photo}<span class="emp-pin-initials"${initialsStyle}>${escapeHtml(letters)}</span></div>
                <span class="emp-pin-dot"></span>
            </div>
            <div class="emp-pin-name">${escapeHtml(name)}</div>
        </div>`;
    }

    function popupHtml(employee, extras) {
        const letters = employee.initials || initials(employee.employee_name);
        const portrait = employee.profile_photo_url
            ? `<img class="emp-popup-photo" src="${escapeHtml(employee.profile_photo_url)}" alt="">`
            : `<span class="emp-popup-initials">${escapeHtml(letters)}</span>`;

        return `<div class="emp-popup">
            ${portrait}
            <strong class="emp-popup-name">${escapeHtml(employee.employee_name || 'Employee')}</strong>
            <div>${escapeHtml(employee.mobile || '—')}</div>
            <div class="emp-popup-status">${escapeHtml(extras.status)}</div>
            <div>Last updated: ${escapeHtml(extras.updated)}</div>
            <div>Location: ${escapeHtml(extras.location)}</div>
            <div>Today: ${escapeHtml(extras.km)}</div>
        </div>`;
    }

    function acceptPoints(knownIds, previous, points) {
        const ids = new Set(knownIds || []);
        const fresh = [];
        let last = previous || null;

        (points || []).forEach((raw) => {
            const next = asPoint(raw);
            if (!next) return;
            if (next.id != null && ids.has(next.id)) return;
            if (next.id != null) ids.add(next.id);
            if (last && sameCoord(last, next)) {
                last = next;
                return;
            }
            fresh.push(next);
            last = next;
        });

        return { ids: Array.from(ids), fresh };
    }

    function dedupePath(points) {
        const result = [];
        (points || []).forEach((point) => {
            const last = result[result.length - 1];
            if (last && sameCoord(last, point)) return;
            result.push(point);
        });

        return result;
    }

    function planMotion(from, points, now, pollMs, animate) {
        const path = dedupePath((points || []).map(asPoint).filter(Boolean));
        if (!from || path.length === 0) {
            return { mode: 'idle', reason: 'empty', segments: [], bearing: null };
        }

        const vertices = [from, ...path];
        let total = 0;
        const lengths = [];
        for (let index = 1; index < vertices.length; index++) {
            const meters = distanceMeters(vertices[index - 1], vertices[index]);
            lengths.push(meters);
            total += meters;
        }

        const last = path[path.length - 1];
        if (animate === false || total < SNAP_UNDER_METERS || total > TELEPORT_METERS) {
            return {
                mode: 'snap',
                reason: total > TELEPORT_METERS ? 'teleport' : (animate === false ? 'reduced-motion' : 'jitter'),
                segments: [],
                bearing: null,
                destination: last,
            };
        }

        const duration = Math.max(900, Math.min(pollMs * 0.92, total * 12));
        let cursor = now;
        const segments = lengths.map((meters, index) => {
            const segmentDuration = Math.max(16, duration * (meters / total));
            const segment = {
                from: vertices[index],
                to: vertices[index + 1],
                id: vertices[index + 1].id,
                start: cursor,
                duration: segmentDuration,
                bearing: bearing(vertices[index], vertices[index + 1]),
            };
            cursor += segmentDuration;

            return segment;
        });

        return {
            mode: 'animate',
            reason: 'move',
            segments,
            bearing: segments[segments.length - 1].bearing,
            destination: last,
        };
    }

    function positionAt(motion, now) {
        if (!motion || motion.mode !== 'animate' || !motion.segments?.length) {
            return null;
        }

        let completedCount = 0;
        let active = motion.segments[motion.segments.length - 1];
        let done = true;
        for (const segment of motion.segments) {
            if (now >= segment.start + segment.duration) {
                completedCount += 1;
                active = segment;
                continue;
            }
            done = false;
            active = segment;
            break;
        }

        if (done) {
            return {
                lat: active.to.lat,
                lng: active.to.lng,
                bearing: active.bearing,
                done: true,
                completedCount,
            };
        }

        const progress = active.duration <= 0 ? 1 : ease((now - active.start) / active.duration);

        return {
            lat: lerp(active.from.lat, active.to.lat, progress),
            lng: lerp(active.from.lng, active.to.lng, progress),
            bearing: active.bearing,
            done: false,
            completedCount,
        };
    }

    function liveTail(coords, current) {
        const next = (coords || []).map((pair) => [pair[0], pair[1]]);
        if (!current || !Number.isFinite(current.lat) || !Number.isFinite(current.lng)) {
            return next;
        }
        const last = next[next.length - 1];
        if (last && Math.abs(last[0] - current.lat) < 1e-7 && Math.abs(last[1] - current.lng) < 1e-7) {
            return next;
        }
        next.push([current.lat, current.lng]);

        return next;
    }

    function shouldReloadRoute(currentId, nextId) {
        return currentId !== nextId;
    }

    function samePath(pending, points) {
        if (!pending || pending.length !== points.length) return false;

        return pending.every((item, index) => sameCoord(item, points[index]));
    }

    return {
        SNAP_UNDER_METERS,
        TELEPORT_METERS,
        distanceMeters,
        bearing,
        ease,
        initials,
        markerTone,
        popupStatus,
        markerHtml,
        popupHtml,
        acceptPoints,
        dedupePath,
        planMotion,
        positionAt,
        liveTail,
        shouldReloadRoute,
        sameCoord,
        samePath,
        escapeHtml,
    };
});
