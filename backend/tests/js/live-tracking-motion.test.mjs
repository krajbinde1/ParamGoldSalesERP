import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const code = fs.readFileSync(new URL('../../public/js/live-tracking-motion.js', import.meta.url), 'utf8');
const context = vm.createContext({ console });
vm.runInContext(code, context);
const motion = context.LiveTrackingMotion;

const umesh = { lat: 18.5204, lng: 73.8567 };
const nextFix = { lat: 18.5240, lng: 73.8600, id: 11 };
const thirdFix = { lat: 18.5275, lng: 73.8635, id: 12 };

function between(value, start, end) {
    const low = Math.min(start, end);
    const high = Math.max(start, end);
    return value > low && value < high;
}

const planned = motion.planMotion(umesh, [nextFix], 1_000, 12_000, true);
assert.equal(planned.mode, 'animate');
const started = motion.positionAt(planned, 1_000);
const midway = motion.positionAt(planned, 1_000 + planned.segments[0].duration / 2);
const arrived = motion.positionAt(planned, 1_000 + planned.segments[0].duration + 5);
assert.ok(Math.abs(started.lat - umesh.lat) < 1e-6, 'marker starts at the previous GPS fix');
assert.ok(between(midway.lat, umesh.lat, nextFix.lat), 'midpoint stays between the two GPS fixes');
assert.ok(between(midway.lng, umesh.lng, nextFix.lng), 'midpoint longitude stays on the segment');
assert.ok(Math.abs(arrived.lat - nextFix.lat) < 1e-6);
assert.equal(arrived.done, true);
assert.equal(arrived.completedCount, 1);

const north = motion.bearing({ lat: 18.5, lng: 73.8 }, { lat: 18.6, lng: 73.8 });
const east = motion.bearing({ lat: 18.5, lng: 73.8 }, { lat: 18.5, lng: 73.9 });
assert.ok(north < 1 || north > 359);
assert.ok(Math.abs(east - 90) < 1);

const jitter = motion.planMotion(umesh, [{ lat: umesh.lat + 0.00002, lng: umesh.lng, id: 3 }], 0, 12_000, true);
assert.equal(jitter.mode, 'snap');
assert.equal(jitter.reason, 'jitter');
const teleport = motion.planMotion(umesh, [{ lat: 19.2, lng: 75.2, id: 4 }], 0, 12_000, true);
assert.equal(teleport.mode, 'snap');
assert.equal(teleport.reason, 'teleport');

const firstEmployee = motion.planMotion(umesh, [nextFix], 0, 12_000, true);
const secondEmployee = motion.planMotion({ lat: 19.1, lng: 72.8 }, [{ lat: 19.11, lng: 72.82, id: 21 }], 0, 12_000, true);
assert.notEqual(firstEmployee.destination.lat, secondEmployee.destination.lat);
assert.equal(motion.positionAt(firstEmployee, 10).lat !== motion.positionAt(secondEmployee, 10).lat, true);

const photoMarker = motion.markerHtml({
    employee_name: 'Umesh Wagh',
    initials: 'UW',
    profile_photo_url: '/storage/employees/umesh.jpg',
    status: 'moving',
    bearing: 90,
}, true);
assert.match(photoMarker, /Umesh Wagh/);
assert.match(photoMarker, /emp-pin-photo/);
assert.match(photoMarker, /data-status="moving"/);
assert.match(photoMarker, /emp-pin-name/);

const fallbackMarker = motion.markerHtml({
    employee_name: 'Umesh Wagh',
    status: 'offline',
}, false);
assert.match(fallbackMarker, /UW/);
assert.doesNotMatch(fallbackMarker, /emp-pin-photo/);
assert.match(fallbackMarker, /data-status="offline"/);
assert.equal(motion.markerTone('stopped'), 'stopped');
assert.equal(motion.markerTone('delayed'), 'offline');
assert.equal(motion.popupStatus('delayed'), 'Offline');
assert.equal(motion.popupStatus('moving'), 'Moving');

const popup = motion.popupHtml({
    employee_name: 'Umesh Wagh',
    initials: 'UW',
    mobile: '9876543210',
    profile_photo_url: '/storage/employees/umesh.jpg',
}, {
    status: 'Moving',
    updated: '6:20 PM (12 sec ago)',
    location: '18.52040, 73.85670',
    km: '4.2 km',
});
assert.match(popup, /emp-popup-photo/);
assert.match(popup, /Umesh Wagh/);
assert.match(popup, /9876543210/);
assert.match(popup, /Moving/);
assert.match(popup, /Last updated: 6:20 PM/);
assert.match(popup, /18\.52040, 73\.85670/);
assert.match(popup, /4\.2 km/);

const accepted = motion.acceptPoints([], umesh, [
    { id: 10, latitude: umesh.lat, longitude: umesh.lng },
    { id: 11, latitude: nextFix.lat, longitude: nextFix.lng },
    { id: 11, latitude: nextFix.lat, longitude: nextFix.lng },
    { id: 12, latitude: thirdFix.lat, longitude: thirdFix.lng },
]);
assert.equal(accepted.fresh.map((point) => point.id).join(','), '11,12');
const again = motion.acceptPoints(accepted.ids, thirdFix, [
    { id: 11, latitude: nextFix.lat, longitude: nextFix.lng },
    { id: 12, latitude: thirdFix.lat, longitude: thirdFix.lng },
]);
assert.equal(again.fresh.length, 0);

const committed = [[umesh.lat, umesh.lng]];
const tail = motion.liveTail(committed, midway);
assert.equal(tail.length, 2);
assert.equal(tail[0][0], umesh.lat);
assert.equal(tail[tail.length - 1][0], midway.lat);
const settled = motion.liveTail([[nextFix.lat, nextFix.lng]], nextFix);
assert.equal(settled.length, 1);

assert.equal(motion.shouldReloadRoute(7, 8), true);
assert.equal(motion.shouldReloadRoute(7, 7), false);

const pending = [{ lat: nextFix.lat, lng: nextFix.lng, id: 11 }];
assert.equal(motion.samePath(pending, [{ lat: nextFix.lat, lng: nextFix.lng, id: 11 }]), true);
assert.equal(motion.samePath(pending, [thirdFix]), false);

console.log('live-tracking-motion: 10 behavior checks passed');
