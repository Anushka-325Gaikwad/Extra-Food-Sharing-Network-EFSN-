<?php
/**
 * Network Location Map & Routing Portal
 * Dynamic geocoding of nearby Donors, NGOs and Logistics Hubs
 */
require_once 'config.php';

// Auth Guard: Force login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];
$userLoc = $_SESSION['location'] ?? 'Delhi, India';
$userName = $_SESSION['name'];

// 1. Fetch active food listings (Donors) with pending/available status
$activeDonors = [];
try {
    $activeDonors = $pdo->query("
        SELECT f.id, f.food_name, f.quantity, f.location, u.name as donor_name, u.contact as donor_contact
        FROM food_listings f
        JOIN users u ON f.donor_id = u.id
        WHERE f.status = 'available' AND f.expiry_time > NOW()
    ")->fetchAll();
} catch (PDOException $e) {}

// 2. Fetch NGO locations
$ngos = [];
try {
    $ngos = $pdo->query("
        SELECT id, name, location, contact 
        FROM users 
        WHERE role = 'ngo'
    ")->fetchAll();
} catch (PDOException $e) {}

// 3. Fetch active Logistics/Volunteer locations
$couriers = [];
try {
    $couriers = $pdo->query("
        SELECT id, name, location, role, contact 
        FROM users 
        WHERE role IN ('volunteer', 'logistics')
    ")->fetchAll();
} catch (PDOException $e) {}

require_once 'header.php';
?>

<main class="main-content">
    
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px;">
        <div>
            <h1 class="gradient-text"><i class="fa-solid fa-map-location-dot"></i> Live Network Map</h1>
            <p style="color: var(--text-secondary); margin-bottom: 0;">Visualize active surplus food listings, nearby NGO hubs, and coordinate pickups in real-time.</p>
        </div>
        <a href="dashboard.php" class="btn btn-outline btn-sm"><i class="fa-solid fa-arrow-left"></i> Dashboard</a>
    </div>

    <div style="display: grid; grid-template-columns: 3fr 1fr; gap: 30px; margin-bottom: 40px;">
        
        <!-- Live Map Container -->
        <div class="glass-container" style="padding: 15px; position: relative;">
            <div id="liveNetworkMap" style="height: 600px; width: 100%; border-radius: var(--radius-md); background: #0c101b; overflow: hidden; position: relative;">
                <div id="mapLoader" style="position: absolute; top:0; left:0; width:100%; height:100%; display:flex; align-items:center; justify-content:center; background: rgba(9, 13, 22, 0.9); z-index: 1000; color: #fff; font-size: 1.1rem; font-family: var(--font-display);">
                    <i class="fa-solid fa-satellite fa-spin" style="margin-right: 10px; color: var(--primary);"></i> Loading satellite routing points...
                </div>
            </div>
        </div>

        <!-- Sidebar Navigation Support & Distances -->
        <div class="glass-container" style="padding: 20px; max-height: 630px; overflow-y: auto;">
            <h3 style="font-size: 1.1rem; color: var(--accent); margin-bottom: 15px; border-bottom: 1px solid var(--border-glass); padding-bottom: 8px;">
                <i class="fa-solid fa-location-crosshairs"></i> Nearby Locations
            </h3>
            
            <div id="locationList" style="display: flex; flex-direction: column; gap: 12px;">
                <div style="font-size: 0.85rem; color: var(--text-muted); text-align: center; padding: 20px 0;">Geocoding locations...</div>
            </div>
        </div>

    </div>

</main>

<!-- Leaflet Library integration -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const userLocAddr = <?php echo json_encode($userLoc); ?>;
    const userRole = <?php echo json_encode($userRole); ?>;
    const userName = <?php echo json_encode($userName); ?>;
    
    const donors = <?php echo json_encode($activeDonors); ?>;
    const ngos = <?php echo json_encode($ngos); ?>;
    const couriers = <?php echo json_encode($couriers); ?>;

    // Cache geocoded coords to avoid Nominatim rate-limiting
    const coordCache = {};

    async function geocode(address) {
        if (!address) return null;
        if (coordCache[address]) return coordCache[address];
        
        try {
            const query = encodeURIComponent(address + ", India");
            const response = await fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${query}&limit=1`);
            const data = await response.json();
            if (data && data.length > 0) {
                const coords = [parseFloat(data[0].lat), parseFloat(data[0].lon)];
                coordCache[address] = coords;
                return coords;
            }
        } catch (e) {
            console.error("Geocoding failed for: " + address, e);
        }
        return null;
    }

    // Distance calculation (Haversine formula in km)
    function calculateDistance(lat1, lon1, lat2, lon2) {
        const R = 6371; // Radius of earth in km
        const dLat = (lat2 - lat1) * Math.PI / 180;
        const dLon = (lon2 - lon1) * Math.PI / 180;
        const a = 
            Math.sin(dLat/2) * Math.sin(dLat/2) +
            Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) * 
            Math.sin(dLon/2) * Math.sin(dLon/2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
        return parseFloat((R * c).toFixed(2));
    }

    async function initMap() {
        let centerCoords = [28.6139, 77.2090]; // Delhi fallback
        
        // Geocode active user's location
        const myCoords = await geocode(userLocAddr);
        if (myCoords) centerCoords = myCoords;

        document.getElementById('mapLoader').style.display = 'none';

        // Init Leaflet map
        const map = L.map('liveNetworkMap').setView(centerCoords, 11);

        // Dark tiling
        L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
            attribution: '&copy; OpenStreetMap contributors &copy; CARTO'
        }).addTo(map);

        const markers = [];
        const locationPanelItems = [];

        // CSS pulsing for current user marker
        if (!document.getElementById('mapPulseStyle')) {
            const style = document.createElement('style');
            style.id = 'mapPulseStyle';
            style.innerHTML = `
                @keyframes selfPulse {
                    0% { transform: scale(0.9); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
                    70% { transform: scale(1.1); box-shadow: 0 0 0 8px rgba(16, 185, 129, 0); }
                    100% { transform: scale(0.9); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
                }
            `;
            document.head.appendChild(style);
        }

        const createPinIcon = (color, text, isSelf = false) => L.divIcon({
            html: `<div style="background-color: ${color}; width: 14px; height: 14px; border: 2px solid #fff; border-radius: 50%; box-shadow: 0 0 10px ${color}; ${isSelf ? 'animation: selfPulse 1.5s infinite;' : ''}"></div>
                   <div style="font-size: 0.7rem; color: #fff; background: rgba(9, 13, 22, 0.9); border: 1px solid var(--border-glass); border-radius: 3px; padding: 1px 4px; white-space: nowrap; margin-top: 5px; margin-left: -20px; font-weight: bold;">${text}</div>`,
            className: 'custom-pin-icon',
            iconSize: [14, 14],
            iconAnchor: [7, 7]
        });

        // 1. Current User
        if (myCoords) {
            L.marker(myCoords, { icon: createPinIcon('#10b981', 'You (Current)', true) }).addTo(map)
             .bindPopup(`<strong>Your Base Location:</strong><br>${userLocAddr}`);
            markers.push(myCoords);
        }

        // 2. Plot active Donors
        for (let donor of donors) {
            const coords = await geocode(donor.location);
            if (coords) {
                const dist = myCoords ? calculateDistance(myCoords[0], myCoords[1], coords[0], coords[1]) : null;
                const distStr = dist !== null ? `${dist} km away` : 'Distance unavailable';
                
                const popupHtml = `
                    <div style="color:#fff; font-family:sans-serif;">
                        <strong style="color:var(--accent); font-size:0.95rem;">🏪 Donor: ${donor.donor_name}</strong><br>
                        <strong>Food:</strong> ${donor.food_name}<br>
                        <strong>Qty:</strong> ${donor.quantity} servings<br>
                        <strong>Pickup:</strong> ${donor.location}<br>
                        <strong>Phone:</strong> ${donor.donor_contact || '+91 98765 43210'}<br>
                        <a href="https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(donor.location)}" target="_blank" style="display:inline-block; margin-top:8px; font-size:0.8rem; color:#38bdf8; text-decoration:underline;">Directions</a>
                    </div>
                `;
                L.marker(coords, { icon: createPinIcon('#fbbf24', '🏪 Food') }).addTo(map).bindPopup(popupHtml);
                markers.push(coords);

                locationPanelItems.push({
                    name: donor.donor_name,
                    sub: `Food: ${donor.food_name} (${donor.quantity} servings)`,
                    loc: donor.location,
                    dist: dist,
                    distStr: distStr,
                    type: 'donor',
                    color: '#fbbf24'
                });
            }
        }

        // 3. Plot NGOs
        for (let ngo of ngos) {
            // Skip placing marker on ourselves if current user is this NGO
            if (ngo.id == <?php echo $userId; ?>) continue;
            
            const coords = await geocode(ngo.location);
            if (coords) {
                const dist = myCoords ? calculateDistance(myCoords[0], myCoords[1], coords[0], coords[1]) : null;
                const distStr = dist !== null ? `${dist} km away` : 'Distance unavailable';
                
                const popupHtml = `
                    <div style="color:#fff; font-family:sans-serif;">
                        <strong style="color:#38bdf8; font-size:0.95rem;">🏢 NGO: ${ngo.name}</strong><br>
                        <strong>Address:</strong> ${ngo.location}<br>
                        <strong>Phone:</strong> ${ngo.contact || '+91 98765 43210'}<br>
                        <a href="https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(ngo.location)}" target="_blank" style="display:inline-block; margin-top:8px; font-size:0.8rem; color:#38bdf8; text-decoration:underline;">Directions</a>
                    </div>
                `;
                L.marker(coords, { icon: createPinIcon('#38bdf8', '🏢 NGO') }).addTo(map).bindPopup(popupHtml);
                markers.push(coords);

                locationPanelItems.push({
                    name: ngo.name,
                    sub: 'NGO Distribution Shelter',
                    loc: ngo.location,
                    dist: dist,
                    distStr: distStr,
                    type: 'ngo',
                    color: '#38bdf8'
                });
            }
        }

        // 4. Plot Couriers (Volunteers & Logistics Partners)
        for (let c of couriers) {
            if (c.id == <?php echo $userId; ?>) continue;
            const coords = await geocode(c.location);
            if (coords) {
                const dist = myCoords ? calculateDistance(myCoords[0], myCoords[1], coords[0], coords[1]) : null;
                const distStr = dist !== null ? `${dist} km away` : 'Distance unavailable';
                
                const roleLabel = c.role === 'logistics' ? 'Logistics Fleet' : 'Volunteer';
                const popupHtml = `
                    <div style="color:#fff; font-family:sans-serif;">
                        <strong style="color:#a7f3d0; font-size:0.95rem;">🚲 ${roleLabel}: ${c.name}</strong><br>
                        <strong>Station:</strong> ${c.location}<br>
                        <strong>Phone:</strong> ${c.contact || '+91 98765 43210'}
                    </div>
                `;
                L.marker(coords, { icon: createPinIcon('#a7f3d0', '🚲 Courier') }).addTo(map).bindPopup(popupHtml);
                markers.push(coords);
            }
        }

        // Fit map bounds to show all markers beautifully
        if (markers.length > 0) {
            const bounds = L.latLngBounds(markers);
            map.fitBounds(bounds, { padding: [50, 50] });
        }

        // Render Nearby locations sidebar sorted by proximity
        locationPanelItems.sort((a, b) => {
            if (a.dist === null) return 1;
            if (b.dist === null) return -1;
            return a.dist - b.dist;
        });

        const listContainer = document.getElementById('locationList');
        if (locationPanelItems.length === 0) {
            listContainer.innerHTML = `<div style="font-size:0.85rem; color:var(--text-muted); text-align:center; padding:20px 0;">No active donor or NGO destinations in this sector.</div>`;
        } else {
            listContainer.innerHTML = locationPanelItems.map(item => `
                <div class="glass-card" style="padding:15px; border-left:3px solid ${item.color};">
                    <strong style="font-size:0.92rem; display:block; color:#ffffff;">${item.name}</strong>
                    <span style="font-size:0.75rem; display:block; color:var(--text-secondary); margin-bottom:8px;">${item.sub}</span>
                    <div style="font-size:0.78rem; color:var(--text-muted); margin-bottom:10px;">
                        <i class="fa-solid fa-location-dot" style="color: ${item.color};"></i> ${item.loc}<br>
                        <i class="fa-solid fa-road" style="color: #ffffff;"></i> ${item.distStr}
                    </div>
                    <a href="https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(item.loc)}" target="_blank" class="btn btn-outline btn-sm" style="width:100%; font-size:0.72rem; padding:4px 8px;">
                        <i class="fa-solid fa-location-arrow"></i> Navigate Route
                    </a>
                </div>
            `).join('');
        }
    }

    initMap();
});
</script>

</body>
</html>
