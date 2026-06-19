const express = require('express');
const { WebSocketServer } = require('ws');
const http = require('http');
const path = require('path');

const app = express();
const server = http.createServer(app);
const wss = new WebSocketServer({ server });

// ================= [ আপনার পরিবর্তন করার জায়গা ] =================
// পরবর্তীতে আসল API Key এবং Live Video ID এখানে বসাবেন
const API_KEY = process.env.API_KEY || 'AIzaSyCLxAbJAze5n1TYKZHeC4-KLlE60_DyYSQ'; 
const VIDEO_ID = process.env.VIDEO_ID || 'YOUR_REAL_VIDEO_ID_HERE'; 
// =============================================================

let liveChatId = null;
let nextPageToken = null;

app.use(express.static(path.join(__dirname)));

async function getLiveChatId() {
    try {
        const res = await fetch(`https://www.googleapis.com/youtube/v3/videos?part=liveStreamingDetails&id=${VIDEO_ID}&key=${API_KEY}`);
        const data = await res.json();
        if (data.items && data.items[0]) {
            liveChatId = data.items[0].liveStreamingDetails.activeLiveChatId;
            console.log("✅ Live Chat ID Found:", liveChatId);
            getChatMessages();
        } else {
            console.log("❌ Live stream active না অথবা Video ID ভুল।");
            setTimeout(getLiveChatId, 10000);
        }
    } catch (err) {
        console.error("Error fetching chat ID:", err);
    }
}

async function getChatMessages() {
    if (!liveChatId) return;
    let url = `https://www.googleapis.com/youtube/v3/liveChat/messages?liveChatId=${liveChatId}&part=snippet&key=${API_KEY}&maxResults=200`;
    if (nextPageToken) url += `&pageToken=${nextPageToken}`;

    try {
        const res = await fetch(url);
        const data = await res.json();
        nextPageToken = data.nextPageToken;

        if (data.items) {
            data.items.forEach(item => {
                const message = item.snippet.displayMessage.toLowerCase().trim().replace(/\s/g, '');
                wss.clients.forEach(client => {
                    if (client.readyState === 1) {
                        client.send(JSON.stringify({ country: message }));
                    }
                });
            });
        }
    } catch (err) {
        console.error("Error fetching messages:", err);
    }
    setTimeout(getChatMessages, 4000);
}

getLiveChatId();

const PORT = process.env.PORT || 3000;
server.listen(PORT, () => {
    console.log(`🚀 Server running on port ${PORT}`);
});
