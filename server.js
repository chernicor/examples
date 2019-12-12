// Example of socket server for education app
var cache = require("./cache");
var auth = require("./auth");
var fs = require("fs");
var os = require("os");
var https = require("https");
var socketIO = require("socket.io");
var express = require("express"),
  app = express();
var cors = require("cors");
var CronJob = require("cron").CronJob;
var is_develop = process.env.NODE_ENV !== "production";
import lessonReducer from "./reducers";
import {
  startAppointmentLesson,
  finishAppointmentLesson,
  getAppointmentState,
  saveCachedAppointmentsState,
  createChatMessage
} from "./appointment";

new CronJob(
  "60 * * * * *",
  function() {
    //saving appointment state to mysql every minute
    saveCachedAppointmentsState();
  },
  null,
  true,
  "Europe/Moscow"
);

//Cross-Origin policy - only accept from self domain
app.use(
  cors({
    origin: is_develop ? "https://localhost:443" : "https://edu.app:443",
    optionsSuccessStatus: 200
  })
);

const privateKey = fs.readFileSync('/home/node/letsencrypt/privkey.pem', 'utf8');
const certificate = fs.readFileSync('/home/node/letsencrypt/cert.pem', 'utf8');
const ca = fs.readFileSync('/home/node/letsencrypt/chain.pem', 'utf8');

var httpsServer = https.createServer({
  key: privateKey,
	cert: certificate,
	ca: ca
},app);

var io = socketIO.listen(httpsServer);
io.sockets.on("connection", function(socket) {
  let token = socket.handshake.query.token;
  let appointmentId = socket.handshake.query.appointmentId;

  auth
    .checkAccess(appointmentId, token, socket.id)
    .then(({ user, appointment }) => {
      auth.joinLesson(appointmentId, user.id, user.groups, socket.id);

      socket.on("disconnect", reason => {
        auth.leaveLesson(appointmentId, user.id, user.groups);
      });

      socket.on("check lesson members", () => {
        let room = appointmentId;
        let clientsInRoom = io.sockets.adapter.rooms[room];
        let numClients = clientsInRoom
          ? Object.keys(clientsInRoom.sockets).length
          : 0;
        if (numClients === 2) {
          io.sockets.in(room).emit("ready");
        }
      });

      socket.on("join lesson", () => {
        //join room (for webrtc and state dispatch sending to second user)
        let room = appointmentId;
        let clientsInRoom = io.sockets.adapter.rooms[room];
        let numClients = clientsInRoom
          ? Object.keys(clientsInRoom.sockets).length
          : 0;
        //пользователь может занять только одно место в комнате
        if (numClients === 0) {
          socket.join(room);
          socket.emit("created", room, socket.id);
        } else if (numClients === 1) {
          io.sockets.in(room).emit("join", room);
          socket.join(room);
          socket.emit("joined", room, socket.id);
          startAppointmentLesson(appointmentId)
            .then(startedon => {
              io.sockets.in(room).emit("ready", { startedon });
            });
        } else {
          // max two clients
          socket.emit("full", room);
        }
        getAppointmentState(appointmentId)
          .then(lessonState => {
            socket.emit("joined lesson", {
              state: lessonState,
              finishedon: appointment.finishedon
            });
          })
          .catch(error => {
            socket.emit("joined lesson", {
              state: {},
              finished: appointment.finishedon ? true : false
            });
          });
      });

      socket.on("finish lesson", () => {
        let room = appointmentId;
        // only teacher can finish lesson
        if (user.groups["1"]) {
          finishAppointmentLesson(appointmentId)
            .then(finishedon => {
              io.sockets.in(room).emit("finished lesson", { finishedon });
            });
        }
      });

      socket.on("message", function(message) {
        let room = appointmentId;
        socket.broadcast.to(room).emit("message", message);
      });

      socket.on("send dispatch", function(payload) {
        //apply changes in state via reducer
        getAppointmentState(appointmentId)
          .then(initialState => {
            //using redux state reducer here
            let computedState = JSON.stringify(
              lessonReducer(initialState, payload.action)
            );
            //saving state to cache
            cache.set(
              "appointment:" + appointmentId + ":state",
              computedState,
              "EX",
              3600
            );
          });
        //emit action to other user in room
        let room = appointmentId;
        socket.broadcast.to(room).emit("receive dispatch", payload.action);
      });

      socket.on("chat message", text => {
        let room = appointmentId;
        createChatMessage(appointmentId, user.id,text).then(message=>{
          io.sockets.in(room).emit("chat message", message);
        });
      });

      socket.on("ipaddr", function() {
        var ifaces = os.networkInterfaces();
        for (var dev in ifaces) {
          ifaces[dev].forEach(function(details) {
            if (details.family === "IPv4" && details.address !== "127.0.0.1") {
              socket.emit("ipaddr", details.address);
            }
          });
        }
      });
    })
    .catch(() => {
      // user not logged in / has not access to this appointment / already joined lesson
      //TODO emit message for user why cant connect
      socket.disconnect();
    });
});

const PORT = is_develop ? 8080 : 8081;
const HOST = is_develop ? "localhost" : "0.0.0.0"; //'0.0.0.0';

httpsServer.listen(PORT, () => {
  console.log(`Server running at http://` + HOST + `:` + PORT + `/`,`is develop = ${is_develop}`);
});
