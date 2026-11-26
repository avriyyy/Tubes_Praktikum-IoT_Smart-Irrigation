#include <WiFi.h>
#include <time.h>
#include <PubSubClient.h>
#include <Wire.h>
#include <DHT.h>
#include <Adafruit_GFX.h>
#include <Adafruit_SSD1306.h>

// ======================= KONFIGURASI =======================
// WiFi & MQTT (ganti sesuai jaringan/broker Anda)
const char* WIFI_SSID   = "fh_1ec668";
const char* WIFI_PASS   = "wlane13997";
const char* MQTT_SERVER = "mqtt.avriyyy.my.id";
const uint16_t MQTT_PORT = 1883;
const char* MQTT_CLIENT_ID = "esp32-smart-irrigation-01";
const char* DEVICE_ID = "esp32-01";

// Topik MQTT
const char* MQTT_TOPIC_SENSOR  = "iot/sensor";
const char* MQTT_TOPIC_STATUS  = "iot/status";
const unsigned long PUBLISH_MS = 1000;

// Waktu (NTP) untuk timestamp, zona WIB (UTC+7)
const long GMT_OFFSET_SEC = 7 * 3600;

// Pin 
#define PIN_SOIL   34   
#define PIN_DHT    4   
#define PIN_RELAY  26   
#define PIN_SDA    21   
#define PIN_SCL    22  

const bool RELAY_ACTIVE_LOW = true;

const int ADC_DRY = 2728;
const int ADC_WET = 1005;
const int ADC_SAMPLES = 20;

// Moving average (rata-rata bergerak) atas pembacaan soil moisture.
// Tiap siklus 1 detik menghasilkan 1 pembacaan, jadi jendela 5 = rata-rata 5 detik terakhir.
// Makin besar jendela: makin halus, tapi respons makin lambat (lag ~ (N-1)/2 detik).
const int MA_WINDOW = 5;

// Logika kendali
const float SETPOINT = 70.0;               
const unsigned long PUMP_WINDOW_MS = 10000;  
const unsigned long LOOP_MS = 1000;          

// Parameter PID 
float Kp = 10.0;
float Ki = 0.0;
float Kd = 1.0;
const float INTEGRAL_MAX = 100.0;  // batas anti-windup (dalam satuan output %)

// OLED
#define SCREEN_W 128
#define SCREEN_H 64
#define OLED_ADDR 0x3C

// ======================= OBJEK & VARIABEL =======================
DHT dht(PIN_DHT, DHT11);
Adafruit_SSD1306 display(SCREEN_W, SCREEN_H, &Wire, -1);
WiFiClient espClient;
PubSubClient mqtt(espClient);

float soilPct = 0, temperature = NAN, humidity = NAN, vpd = NAN;
int soilRaw = 0;      // hasil moving average (dipakai untuk kendali)
int soilRawInst = 0;  // pembacaan sesaat (rata-rata 20 sampel ADC), untuk pembanding

// Buffer moving average
int maBuf[MA_WINDOW];
int maIndex = 0;
int maCount = 0;
long maSum = 0;

bool pumpOn = false;
float integral = 0, prevError = 0, pidOutput = 0;
unsigned long windowStart = 0;

// TUNING: mode manual untuk uji respons (hapus blok bertanda TUNING jika tidak dipakai)
bool manualMode = false;
float manualDuty = 0;           // duty pompa manual (%)
unsigned long manualEnd = 0;    // pengaman: duty manual otomatis 0 setelah 3 menit

unsigned long lastLoop = 0, lastDht = 0, lastWifiTry = 0, lastMqttTry = 0, lastPublish = 0;
bool lastPublishedPump = false;

// ======================= FUNGSI HELPER =======================
void setRelay(bool on) {
  pumpOn = on;
  digitalWrite(PIN_RELAY, (on == RELAY_ACTIVE_LOW) ? LOW : HIGH);
}

int readSoilRaw() {
  long sum = 0;
  for (int i = 0; i < ADC_SAMPLES; i++) {
    sum += analogRead(PIN_SOIL);
    delay(2);
  }
  return (int)(sum / ADC_SAMPLES);
}

// Moving average: rata-rata MA_WINDOW pembacaan terakhir.
// Saat buffer belum penuh (awal menyala), rata-rata hanya dari data yang ada,
// jadi nilai awal tidak tertarik ke 0.
int movingAverage(int newVal) {
  if (maCount < MA_WINDOW) {
    maBuf[maIndex] = newVal;
    maSum += newVal;
    maCount++;
  } else {
    maSum -= maBuf[maIndex];  // buang data terlama
    maBuf[maIndex] = newVal;
    maSum += newVal;
  }
  maIndex = (maIndex + 1) % MA_WINDOW;
  return (int)(maSum / maCount);
}

float rawToPercent(int raw) {
  float pct = (float)(ADC_DRY - raw) * 100.0f / (float)(ADC_DRY - ADC_WET);
  return constrain(pct, 0.0f, 100.0f);
}

// VPD (kPa) dari suhu (C) dan RH (%)
float calcVPD(float t, float rh) {
  float svp = 0.6108f * exp((17.27f * t) / (t + 237.3f));
  return svp * (1.0f - rh / 100.0f);
}

void readDHT() {
  float t = dht.readTemperature();
  float h = dht.readHumidity();
  if (!isnan(t) && !isnan(h)) {  // simpan hanya jika pembacaan valid
    temperature = t;
    humidity = h;
    vpd = calcVPD(t, h);
  }
}

void resetPID() {
  integral = 0;
  prevError = 0;
  pidOutput = 0;
}

// ======================= KENDALI POMPA =======================
void controlPump(float dt) {
  // TUNING: mode manual melewati PID
  if (manualMode) {
    unsigned long now = millis();
    if (now > manualEnd) manualDuty = 0;  // pengaman 3 menit
    pidOutput = manualDuty;
    if (now - windowStart >= PUMP_WINDOW_MS) windowStart = now;
    unsigned long onTime = (unsigned long)(pidOutput / 100.0f * PUMP_WINDOW_MS);
    setRelay((now - windowStart) < onTime);
    return;
  }

  // Kelembaban >= setpoint: pompa OFF, integral direset
  if (soilPct >= SETPOINT) {
    resetPID();
    prevError = SETPOINT - soilPct;  // hindari lonjakan turunan saat PID aktif kembali
    windowStart = millis();          // jendela baru dimulai saat PID aktif kembali
    setRelay(false);
    return;
  }

  // Kelembaban < setpoint: PID aktif
  float error = SETPOINT - soilPct;
  integral += error * dt;
  if (Ki > 0) {  // anti-windup
    float iMax = INTEGRAL_MAX / Ki;
    integral = constrain(integral, -iMax, iMax);
  }
  float derivative = (error - prevError) / dt;
  prevError = error;

  pidOutput = Kp * error + Ki * integral + Kd * derivative;
  pidOutput = constrain(pidOutput, 0.0f, 100.0f);

  // Time-proportioning: ON selama (output% x 10 detik) di awal tiap jendela
  unsigned long now = millis();
  if (now - windowStart >= PUMP_WINDOW_MS) windowStart = now;
  unsigned long onTime = (unsigned long)(pidOutput / 100.0f * PUMP_WINDOW_MS);
  setRelay((now - windowStart) < onTime);
}

void handleSerial() {
  if (!Serial.available()) return;
  String s = Serial.readStringUntil('\n');
  s.trim();
  if (s.startsWith("kp=")) Kp = s.substring(3).toFloat();
  else if (s.startsWith("ki=")) Ki = s.substring(3).toFloat();
  else if (s.startsWith("kd=")) Kd = s.substring(3).toFloat();
  else if (s.startsWith("u")) {
    manualMode = true;
    manualDuty = constrain(s.substring(1).toFloat(), 0.0f, 100.0f);
    manualEnd = millis() + 180000UL;
    windowStart = millis();
  }
  else if (s == "auto") { manualMode = false; resetPID(); }
  Serial.printf("[cmd] Kp=%.3f Ki=%.4f Kd=%.3f manual=%d duty=%.0f\n",
                Kp, Ki, Kd, manualMode, manualDuty);
}

// ======================= OLED =======================
void updateDisplay() {
  display.clearDisplay();
  display.setTextSize(1);
  display.setTextColor(SSD1306_WHITE);
  display.setCursor(0, 0);
  display.print("Tanah : ");
  display.print(soilPct, 1);
  display.println(" %");
  display.print("Suhu  : ");
  if (isnan(temperature)) display.println("--");
  else { display.print(temperature, 1); display.println(" C"); }
  display.print("Udara : ");
  if (isnan(humidity)) display.println("--");
  else { display.print(humidity, 0); display.println(" %"); }
  display.print("VPD   : ");
  if (isnan(vpd)) display.println("--");
  else { display.print(vpd, 2); display.println(" kPa"); }
  display.print("Pompa : ");
  display.print(pumpOn ? "ON " : "OFF");
  display.print(" PID ");
  display.print(pidOutput, 0);
  display.println("%");
  display.print("WiFi:");
  display.print(WiFi.status() == WL_CONNECTED ? "OK" : "--");
  display.print(" MQTT:");
  display.println(mqtt.connected() ? "OK" : "--");
  display.display();
}

// ======================= KONEKSI =======================
void maintainConnections() {
  unsigned long now = millis();
  if (WiFi.status() != WL_CONNECTED) {
    if (now - lastWifiTry > 10000) {
      lastWifiTry = now;
      WiFi.disconnect();
      WiFi.begin(WIFI_SSID, WIFI_PASS);
    }
    return;
  }
  if (!mqtt.connected() && now - lastMqttTry > 5000) {
    lastMqttTry = now;
    mqtt.connect(MQTT_CLIENT_ID);
  }
  mqtt.loop();
}

void publishData() {
  if (!mqtt.connected()) return;
  char buf[256];
  char tStr[12], hStr[12], vStr[12], ts[40];

  // nilai tidak valid dikirim sebagai null
  if (isnan(temperature)) strcpy(tStr, "null"); else snprintf(tStr, sizeof(tStr), "%.1f", temperature);
  if (isnan(humidity))    strcpy(hStr, "null"); else snprintf(hStr, sizeof(hStr), "%.0f", humidity);
  if (isnan(vpd))         strcpy(vStr, "null"); else snprintf(vStr, sizeof(vStr), "%.2f", vpd);

  // timestamp ISO 8601 WIB; "null" jika NTP belum sinkron
  struct tm t;
  if (getLocalTime(&t, 0)) {
    char tmp[24];
    strftime(tmp, sizeof(tmp), "%Y-%m-%dT%H:%M:%S", &t);
    snprintf(ts, sizeof(ts), "\"%s+07:00\"", tmp);
  } else {
    strcpy(ts, "null");
  }

  snprintf(buf, sizeof(buf),
           "{\"device\":\"%s\",\"soil\":%.1f,\"suhu\":%s,\"rh\":%s,\"pompa\":%d,\"ts\":%s}",
           DEVICE_ID, soilPct, tStr, hStr, pumpOn ? 1 : 0, ts);
  mqtt.publish(MQTT_TOPIC_SENSOR, buf);

  // iot/status: status pompa + VPD + output PID (%) + error PID (%)
  // pid_error = SETPOINT - kelembaban tanah (negatif jika sudah di atas setpoint)
  snprintf(buf, sizeof(buf),
           "{\"pompa\":%d,\"vpd\":%s,\"pid_output\":%.1f,\"pid_error\":%.1f}",
           pumpOn ? 1 : 0, vStr, pidOutput, SETPOINT - soilPct);
  mqtt.publish(MQTT_TOPIC_STATUS, buf);
}

// ======================= SETUP & LOOP =======================
void setup() {
  Serial.begin(115200);

  digitalWrite(PIN_RELAY, HIGH);  
  pinMode(PIN_RELAY, OUTPUT);
  setRelay(false);  // relay OFF saat mulai

  analogReadResolution(12);
  analogSetPinAttenuation(PIN_SOIL, ADC_11db);

  Wire.begin(PIN_SDA, PIN_SCL);
  if (!display.begin(SSD1306_SWITCHCAPVCC, OLED_ADDR)) {
    Serial.println("OLED tidak ditemukan");
  }
  display.clearDisplay();
  display.setTextSize(1);
  display.setTextColor(SSD1306_WHITE);
  display.setCursor(0, 0);
  display.println("Smart Irrigation");
  display.println("Menghubungkan WiFi...");
  display.display();

  dht.begin();

  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  unsigned long t0 = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - t0 < 10000) delay(250);

  mqtt.setServer(MQTT_SERVER, MQTT_PORT);
  // koneksi MQTT dijaga di maintainConnections()
  configTime(GMT_OFFSET_SEC, 0, "pool.ntp.org", "time.google.com");

  readDHT();
  lastLoop = millis();
}

void loop() {
  handleSerial();         // TUNING
  maintainConnections();  // tidak memblokir; sistem tetap jalan tanpa jaringan

  unsigned long now = millis();
  if (now - lastLoop < LOOP_MS) return;
  float dt = (now - lastLoop) / 1000.0f;
  lastLoop = now;

  // 1. Baca soil moisture -> moving average -> konversi ke %
  soilRawInst = readSoilRaw();
  soilRaw = movingAverage(soilRawInst);
  soilPct = rawToPercent(soilRaw);

  // 2. DHT11 + VPD (hanya monitoring), DHT11 maks ~1 Hz -> baca tiap 2 detik
  if (now - lastDht >= 2000) {
    lastDht = now;
    readDHT();
  }

  // 3. Kendali pompa (PID) -> atur relay
  controlPump(dt);

  // 4. OLED
  updateDisplay();

  // 5. MQTT (jika terhubung): tiap PUBLISH_MS, atau segera saat status pompa berubah
  if (now - lastPublish >= PUBLISH_MS || pumpOn != lastPublishedPump) {
    lastPublish = now;
    lastPublishedPump = pumpOn;
    publishData();
  }

  Serial.printf("soil=%.1f%% (raw MA %d, inst %d) T=%.1f RH=%.0f VPD=%.2f pid=%.0f%% pump=%d\n",
                soilPct, soilRaw, soilRawInst, temperature, humidity, vpd, pidOutput, pumpOn);
}