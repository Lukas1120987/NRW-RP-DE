// server.js
const express = require('express');
const fs = require('fs');
const cors = require('cors');
const bodyParser = require('body-parser');

const app = express();
app.use(cors());
app.use(bodyParser.json());

const FILE = 'tickets.json';

// Hilfsfunktion: Tickets laden oder Datei erstellen
function loadTickets() {
  if(!fs.existsSync(FILE)) fs.writeFileSync(FILE, '[]');
  return JSON.parse(fs.readFileSync(FILE));
}

function saveTickets(tickets){
  fs.writeFileSync(FILE, JSON.stringify(tickets, null, 2));
}

// Alle Tickets abrufen
app.get('/tickets', (req, res) => {
  const tickets = loadTickets();
  res.json(tickets);
});

// Neues Ticket erstellen
app.post('/tickets', (req, res) => {
  const tickets = loadTickets();
  const newTicket = {
    id: Date.now(),
    title: req.body.title,
    category: req.body.category,
    message: req.body.message,
    status: req.body.status || "Offen"
  };
  tickets.push(newTicket);
  saveTickets(tickets);
  res.json(newTicket);
});

// Status ändern
app.put('/tickets/:id', (req, res) => {
  const tickets = loadTickets();
  const index = tickets.findIndex(t => t.id == req.params.id);
  if(index !== -1){
    tickets[index] = { ...tickets[index], ...req.body };
    saveTickets(tickets);
    res.json(tickets[index]);
  } else res.status(404).send("Ticket nicht gefunden");
});

// Ticket löschen (optional)
app.delete('/tickets/:id', (req, res) => {
  let tickets = loadTickets();
  tickets = tickets.filter(t => t.id != req.params.id);
  saveTickets(tickets);
  res.sendStatus(204);
});

app.listen(3000, () => console.log('Ticket-Server läuft auf http://localhost:3000'));
