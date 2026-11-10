import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
export default defineConfig({
  plugins: [react()],
  build: { outDir: "../public", emptyOutDir: false },
  server: {
    proxy: Object.fromEntries(
      [
        "/api",
        "/subscribe",
        "/confirm",
        "/u/",
        "/c/",
        "/ready",
        "/signup.css",
      ].map((path) => [path, "http://127.0.0.1:8090"]),
    ),
  },
});
