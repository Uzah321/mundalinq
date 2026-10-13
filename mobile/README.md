# MundaLinq mobile app

Flutter app for Android and iOS, talking to the Laravel API in `../backend`. One app serves buyers and farmers;
farmers switch between buying and selling from the Account tab.

**Buyers**
- Browse and search listings; filter by area, category, sort and delivery; listing details with reviews
- Basket and favourites (stored on the server), farm pages, messages with farms, notifications
- Checkout: collection or delivery per farm, delivery addresses pinned with the phone's location, server-priced quote
- Orders: wait for the farms to confirm, pay by mobile money, show the handover code, confirm receipt, report a
  problem (with photos) and follow it, rate the farm
- Account: profile, addresses, which updates also come by SMS, signed-in devices, password reset, server address.
  Staff accounts confirm sign-in with a code texted to their phone; reviewing farms happens on the website (`/admin`)

**Farmers** ("Sell on MundaLinq" creates a farm; "Switch to selling" opens it)
- Orders: new orders to accept (supplying less if short) or decline, mark ready, send out, hand over with the
  buyer's 6-digit code; call or message the buyer, open the delivery address in Maps
- Listings: create with a first selling option and opening stock, photos from camera or gallery, more ways to buy
  (crates, bags…), add stock, publish, pause, renew, delete
- Farm: verification with ID photos (an unverified farm's draft listings point there instead of offering
  Publish), earnings and payouts, mobile money payout account, last-30-day sales,
  reviews with replies, farm details; several farms per account

## Tools

Installed per user under `C:\Users\dingulwazi.zondo\dev` (no admin rights needed):

| Tool | Path |
|------|------|
| Flutter 3.47.7 (Dart 3.13) | `dev\flutter\bin` |
| Java 21 (Microsoft OpenJDK) | `dev\jdk-21…` |
| Android SDK: platform-tools, NDK 28.2 (Flutter's build requires it); Gradle adds platforms and build-tools as needed | `dev\android-sdk` |

`JAVA_HOME`, `ANDROID_HOME` and the `flutter` and `adb` folders are set in the user environment, so new terminals
find them. `flutter doctor` reports the Android command-line tools (`sdkmanager`) as missing: they were skipped to
save a 155 MB download and are only needed to manage SDK packages by hand. Android Studio is optional; VS Code with
the Flutter extension works.

Fonts (Manrope, Sora) are bundled in `assets/fonts`, so the app needs no font downloads.

## Run against the local API

The phone and the computer must be on the same Wi-Fi. Serve the API on the network, with `APP_URL` in
`backend/.env` set to the computer's address so photo links work on the phone:

```bash
cd backend
php artisan serve --host=0.0.0.0 --port=8000
```

Then, with the phone connected by USB (developer options and USB debugging on):

```bash
cd mobile
flutter run --dart-define=API_BASE_URL=http://192.168.1.216:8000/api/v1
```

The Android emulator reaches the computer at `10.0.2.2`, which is the default when `API_BASE_URL` is left out.

Windows Firewall blocks the phone while the Wi-Fi network is marked **Public**. Either mark it **Private**
(Settings › Network & internet › Wi-Fi › your network) and allow PHP when Windows asks, or allow `php.exe` on
port 8000 in Windows Defender Firewall.

The computer's Wi-Fi address can change when it reconnects. If the app says it can't reach MundaLinq, check the
address with `ipconfig`, update `APP_URL` and rebuild with the new `API_BASE_URL`.

## Build the installer (APK)

```bash
cd mobile
flutter build apk --release --dart-define=API_BASE_URL=http://192.168.1.216:8000/api/v1 --dart-define=SANDBOX_PAYMENTS=true
```

`SANDBOX_PAYMENTS=true` shows the sandbox's test numbers on the payment screen (0771111111 approves; see the backend
README). Leave it out for builds that use a real payment provider.

The APK is `build/app/outputs/flutter-apk/app-release.apk` (about 54 MB, runs on any Android 7.0+ phone). Send it to
a phone (WhatsApp, email, USB), open it and allow installing from that source when Android asks.

To save data, add `--split-per-abi` for one smaller APK per processor type (about 18–22 MB each):
`app-arm64-v8a-release.apk` suits almost every phone from the last several years, and
`app-armeabi-v7a-release.apk` older or entry-level 32-bit phones. If one won't install, use the other or the
universal APK.

Release builds are signed with `android/upload-keystore.jks`, whose passwords are in `android/key.properties`.
Keep both safe and out of version control: Android only installs an update over an existing install when it is
signed with the same key, and the Play Store needs it for every future release.

The local API uses plain HTTP, so the app currently allows unencrypted traffic
(`android:usesCleartextTraffic` in `AndroidManifest.xml`). Remove that once the API is served over HTTPS.

Testers can point an installed app at another server, for example after the computer joins a different Wi-Fi
network, under **Account › Server address**. Photos follow the same address.

## iPhone builds (Codemagic → TestFlight)

iPhone apps can only be built on a Mac, and only installed through Apple: there is no equivalent of sending an
APK. The project builds on Codemagic's cloud Macs (`../codemagic.yaml`, workflow "iPhone app to TestFlight") and
uploads to TestFlight, Apple's app for installing test builds. The iOS project is ready: app ID `com.mundalinq.app`,
name, icons, and the location and local-network permission messages.

One-time setup:

1. **Apple Developer Program** (US$99/year): enrol at developer.apple.com/programs with your Apple ID. Enrolling as
   an organisation needs a D-U-N-S number; as an individual, the app is listed under your own name.
2. **Register the app**: in App Store Connect (appstoreconnect.apple.com) › Apps › **+** › New App: platform iOS,
   name `MundaLinq`, bundle ID `com.mundalinq.app` (register it first under Certificates, Identifiers & Profiles ›
   Identifiers if it isn't offered), any SKU, e.g. `mundalinq-ios`. Then copy the app's **Apple ID** (digits, under
   App Information) into `APP_STORE_APPLE_ID` in `codemagic.yaml`.
3. **API key for Codemagic**: App Store Connect › Users and Access › Integrations › App Store Connect API ›
   generate a key with the **App Manager** role. Download the `.p8` file (it can only be downloaded once) and note
   the Issuer ID and Key ID.
4. **Code on GitHub**: create a private repository and push this project to it (see "Pushing to GitHub" below).
5. **Codemagic** (codemagic.io, free tier includes macOS build minutes): sign in with GitHub and add the
   repository. Under Team settings › Integrations › Developer Portal, add the API key from step 3 with the name
   `MundaLinq App Store Connect`. Under Team settings › Code signing identities › iOS certificates, choose
   **Generate certificate** (Apple Distribution) and keep the password it shows.
6. **Build**: start the "iPhone app to TestFlight" workflow. It runs the tests, builds, signs and uploads; Apple
   then processes the build for 10–30 minutes.
7. **Testers**: in App Store Connect › the app › TestFlight, add testers. Internal testers (people added to your
   App Store Connect team, up to 100) can install straight away; external testers (anyone, by email or public link)
   need a short Beta App Review first. Testers install the **TestFlight** app from the App Store, accept the invite
   and tap Install. Test builds expire after 90 days.

On the phone, the same rules as Android apply: same Wi-Fi as the computer running the API, or change the address
under Account › Server address. iOS asks once for permission to find devices on the local network; allow it.

The app allows plain HTTP for the pilot (`NSAllowsArbitraryLoads` in `ios/Runner/Info.plist`). Remove it once the
API is on HTTPS: App Store review asks for a justification.

## Pushing to GitHub

The project is a Git repository at the `MundaLink` folder. Create an empty **private** repository on github.com
(no README), then from the `MundaLink` folder:

```bash
git remote add origin https://github.com/<you>/mundalinq.git
git push -u origin main
```

Secrets stay out of Git: `backend/.env`, `mobile/android/key.properties` and `upload-keystore.jks` are ignored.

## Trying an order end to end

Use two phones (or sign out and in): order as `buyer@mundalinq.test`, then sign in as `farmer@mundalinq.test`,
tap **Switch to selling** and accept it under Orders. Pay on the buyer's phone with `0771111111`, mark it ready on
the farmer's, and enter the buyer's handover code to complete it. The demo farmer owns every demo farm; switch farm
by tapping its name at the top.

## Layout

- `lib/api`: HTTP client and the API's data shapes
- `lib/state`: session, basket, favourites, catalogue, search, shopping area, farms and the notification badge
  (ChangeNotifiers via `provider`)
- `lib/screens`: the market's tabs (Home, Browse, Favourites, Basket, Account), checkout, orders and payment,
  messages, notifications, problem reports and account settings
- `lib/screens/farm`: the farm's tabs (Orders, Listings, Messages, Farm), listings, verification, earnings
- `lib/widgets`: product card and grid, banner, category strip, shared bits
- `lib/util/format.dart`: money and quantity arithmetic in whole cents and thousandths, as on the web and server
