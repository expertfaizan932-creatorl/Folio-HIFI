# ---------------------------------------------------------------------------
# WorkFolio server image
# ---------------------------------------------------------------------------
# Stage 1 builds the jar with Maven inside the container, so the host needs
# neither JDK nor Maven - only Docker.
# Stage 2 keeps a JRE and nothing else.
# ---------------------------------------------------------------------------

# --- build ------------------------------------------------------------------
FROM maven:3.9-eclipse-temurin-17 AS build

WORKDIR /build

# Copy the POM first and resolve dependencies in their own layer. Source files
# change far more often than the POM, so this keeps rebuilds fast.
COPY pom.xml .
RUN mvn -B -q dependency:go-offline

COPY src ./src
RUN mvn -B -q clean package -DskipTests

# --- runtime ----------------------------------------------------------------
FROM eclipse-temurin:17-jre-jammy AS runtime

# curl is only here for the container healthcheck below.
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl tzdata \
    && rm -rf /var/lib/apt/lists/*

# Run unprivileged. The screenshots this app writes are employee activity
# records, so they should not be readable by anything else on the host.
RUN groupadd --system workfolio \
    && useradd --system --gid workfolio --home /app --shell /usr/sbin/nologin workfolio

WORKDIR /app

COPY --from=build /build/target/WorkFolio-0.0.1-SNAPSHOT.jar app.jar

# Mount point for the database and the screenshot archive. Must be a volume -
# see docker-compose.yml.
RUN mkdir -p /app/data && chown -R workfolio:workfolio /app
VOLUME ["/app/data"]

USER workfolio

EXPOSE 8080

ENV JAVA_OPTS="-XX:MaxRAMPercentage=70 -Djava.security.egd=file:/dev/./urandom" \
    SPRING_PROFILES_ACTIVE="mysql,prod"

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=4 \
    CMD curl -fsS http://localhost:8080/login > /dev/null || exit 1

# exec form via sh so JAVA_OPTS is expanded. The app is the only process, so it
# receives SIGTERM directly and shuts down Spring's context cleanly.
ENTRYPOINT ["sh", "-c", "exec java $JAVA_OPTS -jar /app/app.jar"]
