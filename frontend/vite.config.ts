import { fileURLToPath, URL } from "node:url";
import tailwindcss from "@tailwindcss/vite";
import { tanstackRouter } from "@tanstack/router-plugin/vite";
import react from "@vitejs/plugin-react";
import { defineConfig, loadEnv } from "vite";

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), "");

  return {
    plugins: [tanstackRouter({ target: "react", autoCodeSplitting: true }), react(), tailwindcss()],
    resolve: {
      alias: { "@": fileURLToPath(new URL("./src", import.meta.url)) },
    },
    server: {
      port: 5173,
      // In development the API is proxied, so the browser talks to one origin and no CORS setup is needed.
      // 127.0.0.1 rather than localhost: `php artisan serve` listens on IPv4 only.
      proxy: { "/api": env.MUNDALINQ_BACKEND_URL || "http://127.0.0.1:8000" },
    },
  };
});
