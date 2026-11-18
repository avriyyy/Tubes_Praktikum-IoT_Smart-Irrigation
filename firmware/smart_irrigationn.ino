#include <WiFi.h>
#include <time.h>
#include <PubSubClient.h>
#include <Wire.h>
#include <DHT.h>
#include <Adafruit_GFX.h>
#include <Adafruit_SSD1306.h>

// ======================= KONFIGURASI =======================
// WiFi & MQTT (ganti sesuai jaringan/broker Anda)
const char* WIFI_SSID   = "wle";
const char* WIFI_PASS   = "pppppppp";
const char* MQTT_SERVER = "43.134.3.156";
const uint16_t MQTT_PORT = 1883;
const char* MQTT_CLIENT_ID = "esp32-smart-irrigation-01";
const char* DEVICE_ID = "esp32-01";

// Topik MQTT
const char* MQTT_TOPIC_SENSOR  = "iot/sensor";   
const char* MQTT_TOPIC_STATUS  = "iot/status";   
const char* MQTT_TOPIC_KONTROL = "iot/kontrol";  
const unsigned long PUBLISH_MS = 5000;                    

// Waktu (NTP) untuk timestamp, zona WIB (UTC+7)
const long GMT_OFFSET_SEC = 7 * 3600;

// Pin (sesuai skema rangkaian)
#define PIN_SOIL   34   // AOUT soil moisture (ADC1)
#define PIN_DHT    4    // DATA DHT11
#define PIN_RELAY  26   // IN relay
#define PIN_SDA    21   // OLED SDA
#define PIN_SCL    22   // OLED SCL

const bool RELAY_ACTIVE_LOW = true;

const int ADC_DRY = 2728;  
const int ADC_WET = 1005;  
const int ADC_SAMPLES = 20;

// Logika kendali
const float THRESHOLD_ON = 60.0;  // pemicu: < 60% -> PID aktif
const float SETPOINT     = 70.0;  // target 70%
const unsigned long PUMP_WINDOW_MS = 10000;  // periode time-proportioning
const unsigned long LOOP_MS = 1000;          // siklus utama 1 detik

// Parameter PID (perlu di-tuning saat pengujian)
float Kp = 4.0;
float Ki = 0.05;
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
int soilRaw = 0;

bool pidActive = false;
bool pumpOn = false;
float integral = 0, prevError = 0, pidOutput = 0;
unsigned long windowStart = 0;

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
  pidActive = false;
  integral = 0;
  prevError = 0;
  pidOutput = 0;
}

// ======================= KENDALI POMPA =======================
void controlPump(float dt) {
  // Kelembaban >= setpoint: pompa OFF, PID nonaktif, integral direset
  if (soilPct >= SETPOINT) {
    resetPID();
    setRelay(false);
    return;
  }

  // Pemicu: kelembaban < 60% mengaktifkan PID
  if (soilPct < THRESHOLD_ON && !pidActive) {
    pidActive = true;
    integral = 0;
    prevError = SETPOINT - soilPct;
    windowStart = millis();
  }

  if (!pidActive) {  // belum terpicu (60-70% dari kondisi awal)
    setRelay(false);
    return;
  }

  // Hitung PID
  float error = SETPOINT - soilPct;
  integral += error * dt;
  if (Ki > 0) {  // anti-windup: batasi kontribusi integral
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
    if (mqtt.connect(MQTT_CLIENT_ID)) mqtt.subscribe(MQTT_TOPIC_KONTROL);
  }
  mqtt.loop();
}

// Topik kontrol: standby untuk pengembangan lanjut (hanya dicatat di Serial,belum menggerakkan pompa). 
// Contoh payload: {"pompa":"on","durasi_detik":60}
void onMqttMessage(char* topic, byte* payload, unsigned int length) {
  Serial.print("[kontrol] ");
  Serial.print(topic);
  Serial.print(": ");
  for (unsigned int i = 0; i < length; i++) Serial.print((char)payload[i]);
  Serial.println();
}

void publishData() {
  if (!mqtt.connected()) return;
  char buf[256];
  char tStr[12], hStr[12], vStr[12], ts[40];

  // nilai tidak valid dikirim sebagai null
  if (isnan(temperature)) strcpy(tStr, "null"); else snprintf(tStr, sizeof(tStr), "%.1f", temperature);
  if (isnan(humidity))    strcpy(hStr, "null"); else snprintf(hStr, sizeof(hStr), "%.0f", humidity);
  if (isnan(vpd))         strcpy(vStr, "null"); else snprintf(vStr, sizeof(vStr), "%.2f", vpd);

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

  // petanipintar/status: status pompa + VPD hasil hitung
  snprintf(buf, sizeof(buf), "{\"pompa\":%d,\"vpd\":%s}", pumpOn ? 1 : 0, vStr);
  mqtt.publish(MQTT_TOPIC_STATUS, buf);
}

// ======================= SETUP & LOOP =======================
void setup() {
  Serial.begin(115200);

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
  mqtt.setCallback(onMqttMessage);  // koneksi MQTT dijaga di maintainConnections()
  configTime(GMT_OFFSET_SEC, 0, "pool.ntp.org", "time.google.com");

  readDHT();
  lastLoop = millis();
}

void loop() {
  maintainConnections();  // tidak memblokir; sistem tetap jalan tanpa jaringan

  unsigned long now = millis();
  if (now - lastLoop < LOOP_MS) return;
  float dt = (now - lastLoop) / 1000.0f;
  lastLoop = now;

  // 1. Baca & konversi soil moisture
  soilRaw = readSoilRaw();
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

  // 5. MQTT (jika terhubung): tiap 5 detik, atau segera saat status pompa berubah
  if (now - lastPublish >= PUBLISH_MS || pumpOn != lastPublishedPump) {
    lastPublish = now;
    lastPublishedPump = pumpOn;
    publishData();
  }

  Serial.printf("soil=%.1f%% (raw %d) T=%.1f RH=%.0f VPD=%.2f pid=%.0f%% pump=%d\n",
                soilPct, soilRaw, temperature, humidity, vpd, pidOutput, pumpOn);
}