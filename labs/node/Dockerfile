FROM node:22
WORKDIR /app
COPY node/package*.json ./
RUN npm ci --omit=dev
COPY node/*.js ./
COPY node/views/ ./views/
COPY theme/style.css ./public/style.css
COPY theme/vulns.json ./data/vulns.json
COPY theme/exploits.json ./data/exploits.json
COPY theme/approaches.json ./data/approaches.json
COPY theme/exploits/ ./data/exploits/
EXPOSE 3000
CMD ["npm", "start"]
