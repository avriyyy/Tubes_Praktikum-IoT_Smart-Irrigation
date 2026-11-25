<?php

// Parameter kendali PID pompa — harus sama dengan nilai awal di firmware
// (firmware/smart_irrigationn.ino: SETPOINT, Kp, Ki, Kd).
// Catatan: Kp/Ki/Kd bisa diubah lewat perintah serial di ESP (kp=/ki=/kd=),
// dashboard menampilkan nilai awal ini. Kalau firmware di-tuning, ubah di
// kedua tempat. PID aktif selama tanah < setpoint (tidak ada ambang 60%).
return [
    'setpoint' => 70.0,
    'kp' => 4.0,
    'ki' => 0.05,
    'kd' => 1.0,
];
